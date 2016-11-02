<?php

namespace Despark\Cms\Admin\Traits;

use Despark\Cms\Admin\Helpers\FormBuilder;
use Despark\Cms\Admin\Observers\ImageObserver;
use Despark\Cms\Contracts\AssetsContract;
use Despark\Cms\Contracts\ImageContract;
use Despark\Cms\Exceptions\ModelNotPersistedException;
use Despark\Cms\Exceptions\ModelSanityException;
use Despark\Cms\Helpers\FileHelper;
use Despark\Cms\Models\AdminModel;
use Despark\Cms\Models\File\Temp;
use File as FileFacade;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\UploadedFile;
use Image;

/**
 * Class AdminImage.
 */
trait AdminImage
{
    /**
     * @var array Cache of generated thumb paths
     */
    protected $thumbnailPaths;

    /**
     * @var int|null|false Retina factor
     */
    protected $retinaFactor = 2;

    /**
     * @var string
     */
    protected $currentUploadDir;

    /**
     * @var
     */
    protected $minDimensions;

    /**
     * @var string
     */
    public $uploadDir = 'uploads';

    /**
     * @var
     */
    protected $imageFields;

    /**
     * @var ImageContract|\Despark\Cms\Models\Image
     */
    protected $imageModel;

    /**
     * @var Collection[]
     */
    protected $imagesOfType;

    /**
     * @return MorphMany
     */
    public function images()
    {
        $imageModel = app(ImageContract::class);

        /* @var Model $this */
        return $this->morphMany(get_class($imageModel), 'image', 'resource_model', 'resource_id')
                    ->orderBy('order', 'ASC');
    }

    /**
     * Boot the trait.
     */
    public static function bootAdminImage()
    {
        // Observer for the model
        static::observe(ImageObserver::class);

        // Add Assets
        $assetManager = app(AssetsContract::class);
        $assetManager->addJs('js/flow.js/flow.min.js');
        $assetManager->addJs('js/sortable/Sortable.min.js');

        // We need to listen for booted event and modify the model.
        \Event::listen('eloquent.booted: '.static::class, [new self, 'bootstrapModel']);
    }

    /**
     * @param $model
     * @throws ModelSanityException
     */
    public function bootstrapModel($model)
    {
        if (! property_exists($model, 'rules')) {
            throw new ModelSanityException('Missing rules property for model '.get_class($model));
        }

        if (! $model instanceof AdminModel) {
            throw new ModelSanityException('Model '.get_class($model).' must be instanceof '.AdminModel::class);
        }


        $imageFields = $model->getImageFields();

        if (! is_array($imageFields)) {
            throw new ModelSanityException('No Image fields defined in config for model '.get_class($model));
        }

        // Check for fields collisions
        foreach ($imageFields as $imageFieldName => $imageField) {
            $imageMetaFields = $this->getImageMetaFields($imageFieldName);
            foreach ($imageMetaFields as $metaFieldName => $options) {
            }
        }

        $this->retinaFactor = config('ignicms.images.retina_factor');
        if ($this->retinaFactor) {
            foreach ($imageFields as $fieldName => $field) {
                $this->prepareImageRules($model, 'rules', $fieldName, $field);
            }
        }
    }

    /**
     * @param Model $model
     * @param       $property
     * @param       $fieldName
     * @param       $field
     * @throws \Exception
     */
    protected function prepareImageRules(Model $model, $property, $fieldName, $field)
    {
        $getter = 'get'.studly_case($property);
        $setter = 'set'.studly_case($property);

        if (! method_exists($model, $getter) || ! method_exists($model, $setter)) {
            throw new \Exception('Unexpected missing method on model '.get_class($model));
        }

        // Calculate minimum allowed image size.
        list($minWidth, $minHeight) = $model->getMinAllowedImageSize($field);
        // Set dimensions on the model.
        $model->setMinDimensions($fieldName, ['width' => $minWidth, 'height' => $minHeight]);

        $modelRules = $model->$getter();

        $restrictions = [];
        if ($minWidth) {
            $restrictions[] = 'min_width='.$minWidth;
        }
        if ($minHeight) {
            $restrictions[] = 'min_height='.$minHeight;
        }
        // Prepare model rules.
        if (isset($modelRules[$fieldName])) {
            $rules = explode('|', $modelRules[$fieldName]);
            if (strstr('max:', $modelRules[$fieldName]) === false) {
                $rules[] = 'max:'.config('ignicms.images.max_upload_size');
                $modelRules[$fieldName] = 'dimensions:'.implode(',', $restrictions).
                    '|max:'.config('ignicms.images.max_upload_size');
            }
            // Check to see for dimensions rule and remove it.
            if (strstr('dimensions:', $modelRules[$fieldName]) !== false) {
                foreach ($rules as $key => $rule) {
                    if (strstr('dimensions:', $rule) !== false) {
                        unset($rules[$key]);
                    }
                }
            }
            $rules[] = 'dimensions:'.implode(',', $restrictions);
            $modelRules[$fieldName] = implode('|', $rules);
        } else {
            $modelRules[$fieldName] = 'max:'.config('ignicms.images.max_upload_size').
                '|dimensions:'.implode(',', $restrictions);
        }
        $model->$setter($modelRules);
    }

    /**
     * Save Image.
     */
    public function saveImages()
    {
        $fileIds = [];
        $newFiles = array_get($this->files, 'new.image', []);
        $existingFiles = array_get($this->files, 'image', []);

        // First add new files
        foreach ($newFiles as $files) {
            foreach ($files as $fileId => $file) {
                $fileIds[] = $fileId;
            }
        }

        $collection = Temp::whereIn('id', $fileIds)->get()->keyBy('id');

        $imageFields = $this->getImageFields();

        foreach ($newFiles as $fieldName => $files) {
            // view widget config to see if it is a gallery
            if (! $widgetConfig = $this->getAdminFormField($fieldName)) {
                $fileField = $fieldName;
            } else {
                // Check for file field config and apply it.
                // If no image_field specified default to fieldName.
                if (isset($widgetConfig['image_field'])) {
                    $fileField = $widgetConfig['image_field'];
                } else {
                    $fileField = $fieldName;
                }
            }

            if (! isset($imageFields[$fileField])) {
                throw new \Exception('Configuration not found for file/image field '.$fileField);
            }

            foreach ($files as $fileId => $fileData) {
                //get the temp file
                $file = $collection->get($fileId);
                // Check if we are not deleting the image
                if (isset($fileData['delete']) && $fileData['delete']) {
                    $file->delete();
                } else {
                    $images = $this->manipulateImage($file, $imageFields[$fileField]);
                    // We will save just the source one as a relation.
                    /** @var \Illuminate\Http\File $sourceFile */
                    $sourceFile = $images['original']['source'];


                    $imageModel = app(ImageContract::class, [
                        'original_image' => $sourceFile->getFilename(),
                        'retina_factor' => $this->getRetinaFactor() === false ? null : $this->getRetinaFactor(),
                        'image_type' => $fieldName,
                        'order' => isset($fileData['order']) ? $fileData['order'] : 0,
                        'meta' => isset($fileData['meta']) ? $fileData['meta'] : null,
                    ]);

                    $this->images()->save($imageModel);
                    // Delete temp file
                    $file->delete();
                }
            }
        }

        // Process existing
        // Get existing ids
        $imageIds = [];
        foreach ($existingFiles as $files) {
            foreach ($files as $fileId => $file) {
                $imageIds[] = $fileId;
            }
        }
        if (! empty($imageIds)) {
            $collection = $this->images()->whereIn('id', $imageIds)->get()->keyBy('id');
            
            foreach ($existingFiles as $fieldName => $files) {
                foreach ($files as $fileId => $fileData) {
                    $image = $collection->get($fileId);
                    // Check if not for deletion
                    if (isset($fileData['delete']) && $fileData['delete']) {
                        $image->delete();
                    } else {
                        $image->meta = isset($fileData['meta']) ? $fileData['meta'] : null;
                        $image->order = isset($fileData['order']) ? $fileData['order'] : 0;
                        $image->save();
                    }
                }
            }
        }
        // Now we process single files
        if (isset($this->files['_single']) && $files = $this->files['_single']) {
            $imageFields = $this->getImageFields();

            foreach ($imageFields as $imageType => $options) {
                if ($file = array_get($files, $imageType)) {
                    // Check if not for deletion and delete it.
                    if (is_array($file) && isset($file['delete']) && $file['delete']) {
                        foreach ($this->getImagesOfType($imageType) as $image) {
                            $image->delete();
                        }
                    } else {
                        // First delete unused images
                        foreach ($this->getImagesOfType($imageType) as $image) {
                            $image->delete();
                        }

                        $images = $this->manipulateImage($file, $options);

                        // We will save just the source one as a relation.
                        /** @var \Illuminate\Http\File $sourceFile */
                        $sourceFile = $images['original']['source'];


                        $imageModel = app(ImageContract::class, [
                            'original_image' => $sourceFile->getFilename(),
                            'retina_factor' => $this->getRetinaFactor() === false ? null : $this->getRetinaFactor(),
                            'image_type' => $imageType,
                        ]);
                        unset($this->attributes[$imageType]);
                        $this->images()->save($imageModel);
                    }
                }
            }
        }
    }

    /**
     * @param Temp|UploadedFile $file
     * @param array $options
     * @return array
     * @throws \Exception
     */
    public function manipulateImage($file, array $options)
    {
        // Detect file type
        if ($file instanceof Temp) {
            $sanitizedFilename = $this->sanitizeFilename($file->filename);
        } elseif ($file instanceof UploadedFile) {
            $sanitizedFilename = $this->sanitizeFilename($file->getClientOriginalName());
        } else {
            throw new \Exception('Unexpected file of class '.get_class($file));
        }

        $images = [];
        $pathParts = pathinfo($sanitizedFilename);
        // Move uploaded file and rename it as source file.
        $filename = $pathParts['filename'].'_source.'.$pathParts['extension'];
        // We need to generate unique name if the name is already in use.

        $sourceFile = $file->move($this->getThumbnailPath(), $filename);
        $images['original']['source'] = $sourceFile;


        // If we have retina factor
        if ($this->getRetinaFactor()) {
            // Generate retina image by just copying the source.
            $retinaFilename = $this->generateRetinaName($sanitizedFilename);
            FileFacade::copy($sourceFile->getRealPath(), $this->getThumbnailPath().$retinaFilename);
            $images['original']['retina'] = Image::make($this->getThumbnailPath().$retinaFilename);

            // The original image is scaled down version of the source.
            $originalImage = Image::make($sourceFile->getRealPath());
            $width = round($originalImage->getWidth() / $this->getRetinaFactor());
            $height = round($originalImage->getHeight() / $this->getRetinaFactor());
            $originalImage->resize($width, $height);
            $images['original']['original_file'] = $originalImage->save($this->getThumbnailPath().$sanitizedFilename);

            // Generate thumbs
            foreach ($options['thumbnails'] as $thumbnailName => $thumbnailOptions) {
                // Create retina thumb
                $images['thumbnails'][$thumbnailName]['retina'] = $this->createThumbnail($sourceFile->getRealPath(),
                    $thumbnailName, $this->generateRetinaName($sanitizedFilename),
                    $thumbnailOptions['width'] * $this->getRetinaFactor(),
                    $thumbnailOptions['height'] * $this->getRetinaFactor(),
                    $thumbnailOptions['type'],
                    array_get($thumbnailOptions, 'color'));
                // Create original thumb
                $images['thumbnails'][$thumbnailName]['original'] = $this->createThumbnail($sourceFile->getRealPath(),
                    $thumbnailName, $sanitizedFilename, $thumbnailOptions['width'],
                    $thumbnailOptions['height'], $thumbnailOptions['type'],
                    array_get($thumbnailOptions, 'color'));
            }
        } else {
            // Copy source file.
            $filename = $this->sanitizeFilename($file->getFilename());
            FileFacade::copy($sourceFile->getRealPath(), $this->getThumbnailPath().$filename);
            $images['original']['original_file'] = Image::make($this->getThumbnailPath().$filename);

            // Generate thumbs
            foreach ($options['thumbnails'] as $thumbnailName => $thumbnailOptions) {
                // Create original thumb
                $images['thumbnails'][$thumbnailName]['original'] = $this->createThumbnail($sourceFile->getRealPath(),
                    $thumbnailName, $sanitizedFilename, $thumbnailOptions['width'],
                    $thumbnailOptions['height'], $thumbnailOptions['type']);
            }
        }

        return $images;
    }

    /**
     * @param string $sourceImagePath Source image path
     * @param string $thumbName Thumbnail name
     * @param        $newFileName
     * @param null $width Desired width for resize
     * @param null $height Desired height for resize
     * @param string $resizeType Resize type
     * @return \Intervention\Image\Image
     */
    public function createThumbnail(
        $sourceImagePath,
        $thumbName,
        $newFileName,
        $width = null,
        $height = null,
        $resizeType = 'crop',
        $color = null
    ) {
        $image = Image::make($sourceImagePath);

        $width = ! $width ? null : $width;
        $height = ! $height ? null : $height;

        switch ($resizeType) {
            case 'crop':
                $image->fit($width, $height);
                break;

            case 'resize':
                $image->resize($width, $height, function ($constraint) {
                    $constraint->aspectRatio();
                    $constraint->upsize();
                });
                break;

            case 'fit':
                $image->resize($width, $height, function ($constraint) {
                    $constraint->aspectRatio();
                })->resizeCanvas($width, $height, 'center', false, $color);
        }

        $thumbnailPath = $this->getThumbnailPath($thumbName);

        if (! FileFacade::isDirectory($thumbnailPath)) {
            FileFacade::makeDirectory($thumbnailPath);
        }

        return $image->save($thumbnailPath.$newFileName);
    }

    /**
     * @param $type
     * @return Collection
     * @throws \Exception
     */
    public function getImagesOfType($type)
    {
        // Check to see if type is here.
        if (! in_array($type, $this->getImageTypes())) {
            throw new \Exception('Type not found in model '.self::class);
        }

        return $this->images()->where('image_type', '=', $type)->get();
    }

    /**
     * @param $filename
     * @return string
     */
    protected function sanitizeFilename($filename)
    {
        return FileHelper::sanitizeFilename($filename);
    }

    /**
     * @param $filename
     * @return string
     */
    public function generateRetinaName($filename)
    {
        $pathParts = pathinfo($filename);

        return $pathParts['filename'].'@2x.'.$pathParts['extension'];
    }

    /**
     * @param string $thumbnailType
     * @return string
     */
    public function getThumbnailPath($thumbnailType = 'original')
    {
        if (! isset($this->thumbnailPaths[$thumbnailType])) {
            $this->thumbnailPaths[$thumbnailType] = $this->getCurrentUploadDir().$thumbnailType.DIRECTORY_SEPARATOR;
        }

        return $this->thumbnailPaths[$thumbnailType];
    }

    /**
     * @param        $fieldName
     * @param string $thumbnailType
     * @return bool|string
     */
    public function getImageThumbnailPath($fieldName, $thumbnailType = 'original')
    {
        $modelImageFields = $this->getImageFields();

        if (! array_key_exists($fieldName, $modelImageFields)) {
            return false;
        }

        if (! array_key_exists($thumbnailType, $modelImageFields[$fieldName]['thumbnails'])) {
            $thumbnailType = 'original';
        }

        return $this->getThumbnailPath($thumbnailType).$this->$fieldName;
    }

    /**
     * @return array|null
     */
    public function getImageFields()
    {
        if (! isset($this->imageFields)) {
            $adminThumb = [
                'admin' => [
                    'width' => config('ignicms.images.admin_thumb_width'),
                    'height' => config('ignicms.images.admin_thumb_height'),
                    'type' => 'crop',
                ],
            ];
            $this->imageFields = config('admin.'.$this->identifier.'.image_fields');
            foreach ($this->imageFields as &$imageField) {
                if (! array_key_exists('admin', $imageField['thumbnails'])) {
                    $imageField['thumbnails'] = array_merge($imageField['thumbnails'], $adminThumb);
                }
            }
        }

        return $this->imageFields;
    }

    /**
     * @param $imageFieldName
     * @return array
     */
    public function getImageMetaFields($imageFieldName)
    {
        $imageField = $this->getImageField($imageFieldName);

        // We will always provide alt and title. Unless disabled in config
        $defaultFields = [
            'alt' => [
                'type' => 'text',
                'label' => 'Alternate text',
            ],
            'title' => [
                'type' => 'text',
                'label' => 'Image title',
            ],
        ];
        $fields = [];
        if ($imageField && isset($imageField['fields'])) {
            $fields = $imageField['fields'];
        }

        return array_merge($defaultFields, $fields);
    }

    /**
     * @param $fieldName
     * @param ImageContract $imageModel
     * @param null $actualFieldName
     * @return string
     * @todo improve this and remove actualFieldName so we can relieve the problem with different configs..
     */
    public function getImageMetaFieldsHtml($fieldName, ImageContract $imageModel = null, $actualFieldName = null)
    {
        $formBuilder = new FormBuilder();
        $fields = $this->getImageMetaFields($fieldName);
        $html = '';

        if (is_null($actualFieldName)) {
            $actualFieldName = $fieldName;
        }

        if (is_null($imageModel)) {
            $imageModel = $this->getImageModel()->newInstance();

            $fileId = ':fileId:';
            $isNew = true;
        } else {
            $fileId = $imageModel->getKey();
            $isNew = false;
        }

        // Check for collisions
        $imageModel->checkMetaFieldCollision(array_keys(($fields)));

        $imageModel->setResourceModel($this);

        foreach ($fields as $metaFieldName => $options) {
            $new = $isNew ? '[new]' : '';
            $elementName = '_files'.$new.'[image]['.$actualFieldName.']['.$fileId.'][meta]['.$metaFieldName.']';
            $html .= $formBuilder->field($imageModel, $metaFieldName, $options, $elementName)->render();
        }

        return $html;
    }

    /**
     * @param $imageFieldName
     * @return null|array
     */
    public function getImageField($imageFieldName)
    {
        $imageFields = $this->getImageFields();

        return isset($imageFields[$imageFieldName]) ? $imageFields[$imageFieldName] : null;
    }

    /**
     * @return array
     */
    public function getImageTypes()
    {
        return array_keys($this->getImageFields());
    }

    /**
     * @param $field
     * @return array
     * @throws \Exception
     */
    public function getMinAllowedImageSize($field)
    {
        if (is_string($field)) {
            if (isset($this->getImageFields()[$field])) {
                $field = $this->getImageFields()[$field];
            } else {
                throw new \Exception('Field information missing');
            }
        }

        $minWidth = 0;
        $minHeight = 0;
        foreach ($field['thumbnails'] as $thumbnail) {
            $minWidth = $thumbnail['width'] > $minWidth ? $thumbnail['width'] : $minWidth;
            $minHeight = $thumbnail['height'] > $minHeight ? $thumbnail['height'] : $minHeight;
        }

        $factor = $this->getRetinaFactor() ? $this->getRetinaFactor() : 1;

        return [$minWidth * $factor, $minHeight * $factor];
    }

    /**
     * @return array|mixed|string
     * @throws ModelNotPersistedException
     */
    public function getCurrentUploadDir()
    {
        if (! isset($this->currentUploadDir)) {
            $modelDir = $this->getIdentifier();
            $this->currentUploadDir = public_path($this->uploadDir).DIRECTORY_SEPARATOR.$modelDir.
                DIRECTORY_SEPARATOR.$this->getKey().DIRECTORY_SEPARATOR;
        }

        return $this->currentUploadDir;
    }

    /**
     * @return bool
     */
    public function hasImages($type = null)
    {
        if ($type) {
            return $this->images()->where('image_type', '=', $type)->exists();
        }

        return (bool) count($this->images);
    }

    /**
     * @param null $type
     * @return mixed
     */
    public function getImages($type = null)
    {
        if ($type) {
            if (! isset($this->imagesOfType[$type])) {
                if (count($this->images)) {
                    $this->imagesOfType[$type] = $this->images->where('image_type', $type);
                } else {
                    $this->imagesOfType[$type] = [];
                }
            }

            return $this->imagesOfType[$type];
        }

        return $this->images;
    }


    /**
     * @return mixed
     */
    public function getMinDimensions($field, $asString = false)
    {
        $minDimensions = isset($this->minDimensions[$field]) ? $this->minDimensions[$field] : null;
        if (is_null($minDimensions)) {
            // we try to build it.
            //  Get image fields from the model
            $imageFields = $this->getImageFields();
            $imageField = array_get($imageFields, (string) $field);
            if ($imageField) {
                list($minDimensions['width'], $minDimensions['height']) = $this->getMinAllowedImageSize($imageField);
                // Cache it.
                $this->setMinDimensions($field, $minDimensions);
            } else {
                return;
            }
        }
        if ($asString) {
            if ($minDimensions) {
                if (isset($minDimensions['width']) && $minDimensions['width']
                    && isset($minDimensions['height']) && $minDimensions['height']
                ) {
                    return $minDimensions['width'].'x'.$minDimensions['height'];
                }

                if (isset($minDimensions['width']) && $minDimensions['width']) {
                    return $minDimensions['width'].'px '.trans('admin.images.width');
                }

                if (isset($minDimensions['height']) && $minDimensions['height']) {
                    return $minDimensions['height'].'px '.trans('admin.images.height');
                }
            }

            return;
        }

        return $minDimensions;
    }

    /**
     * @param mixed $minDimensions
     * @return AdminImage
     */
    public function setMinDimensions($field, $minDimensions)
    {
        $this->minDimensions[$field] = $minDimensions;

        return $this;
    }

    /**
     * @return ImageContract|\Despark\Cms\Models\Image
     */
    public function getImageModel()
    {
        if (! isset($this->imageModel)) {
            $this->imageModel = app(ImageContract::class);
        }

        return $this->imageModel;
    }

    /**
     * @param ImageContract|\Despark\Cms\Models\Image $imageModel
     * @return $this
     */
    public function setImageModel($imageModel)
    {
        $this->imageModel = $imageModel;

        return $this;
    }

    /**
     * @return false|int|null
     */
    public function getRetinaFactor()
    {
        return $this->retinaFactor;
    }
}
