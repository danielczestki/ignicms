<?php

namespace Despark\Cms\Models;

use Despark\Cms\Admin\Interfaces\UploadImageInterface;
use Despark\Cms\Contracts\ImageContract;
use Despark\Cms\Exceptions\ImageFieldCollisionException;
use Despark\Cms\Observers\ImageModelObserver;
use Illuminate\Database\Eloquent\Model;

/**
 * Class Image.
 */
class Image extends Model implements ImageContract
{
    /**
     * @var array Cache of generated thumb paths
     */
    protected $thumbnailPaths;

    /**
     * @var
     */
    protected $imageBaseName;

    /**
     * @var Model|UploadImageInterface
     */
    protected $resourceModelInstance;

    /**
     * @var string
     */
    public $uploadDir = 'uploads';

    /**
     * @var
     */
    protected $currentUploadDir;

    /**
     * @var array
     */
    protected $fillable = [
        'image_type',
        'original_image',
        'retina_factor',
        'order',
        'meta',
    ];

    /**
     * The attributes that should be casted to native types.
     *
     * @var array
     */
    protected $casts = [
        'meta' => 'array',
    ];

    /**
     * @var array
     */
    protected $rules = [
        'file' => 'image|max:5000',
    ];

    /**
     * @var array
     */
    protected $meta = [];

    /**
     * @var
     */
    protected $dbColumns;

    /**
     * @var string
     */
    protected $cacheKey = 'igni_image';

    /**
     * Boot model.
     */
    public static function boot()
    {
        parent::boot();
        static::observe(ImageModelObserver::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function image()
    {
        return $this->morphTo('image', 'resource_name', 'resource_id');
    }

    /**
     * @return array
     * @throws \Exception
     */
    public function getAllImages()
    {
        $images = [];

        $images['__source__'] = [$this->getSourceImagePath()];

        $images['original'] = [
            'retina' => $this->getRetinaImagePath(),
            'original' => $this->getOriginalImagePath(),
        ];

        $imageFields = $this->getResourceModel()->getImageFields();

        if (isset($imageFields[$this->image_type]['thumbnails'])) {
            foreach ($imageFields[$this->image_type]['thumbnails'] as $type => $options) {
                $images[$type] = [
                    'retina' => $this->getRetinaImagePath($type),
                    'original' => $this->getOriginalImagePath($type),
                ];
            }
        }

        return $images;
    }

    /**
     * @return Model|UploadImageInterface
     * @throws \Exception
     */
    public function getResourceModel()
    {
        if (! isset($this->resourceModelInstance)) {
            $class = $this->getActualClassNameForMorph($this->resource_model);
            $this->resourceModelInstance = new $class;

            if (! $this->resourceModelInstance instanceof UploadImageInterface) {
                throw new \Exception('Model '.$class.' is not an instance of '.UploadImageInterface::class);
            }
        }

        return $this->resourceModelInstance;
    }

    /**
     * @return string
     */
    public function getSourceImagePath()
    {
        return $this->getThumbnailPath().$this->original_image;
    }

    /**
     * @param string $thumbnailType
     * @return string
     */
    public function getRetinaImagePath($thumbnailType = 'original')
    {
        $pathInfo = pathinfo($this->getImageBaseName());
        $filename = $pathInfo['filename'].'@'.$this->retina_factor.'x.'.$pathInfo['extension'];

        return $this->getThumbnailPath($thumbnailType).$filename;
    }

    /**
     * @param string $thumbnailType
     * @return string
     */
    public function getOriginalImagePath($thumbnailType = 'original')
    {
        return $this->getThumbnailPath($thumbnailType).$this->getImageBaseName();
    }

    /**
     * @return mixed
     */
    public function getImageBaseName()
    {
        if (! isset($this->imageBaseName)) {
            $this->imageBaseName = str_replace('_source', '', $this->original_image);
        }

        return $this->imageBaseName;
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
     * @return array|mixed|string
     * @throws \Exception
     */
    public function getCurrentUploadDir()
    {
        if (! $this->resource_model) {
            throw new \Exception('Missing resource model for model '.$this->getKey());
        }
        if (! isset($this->currentUploadDir)) {
            $modelDir = explode('Models', $this->resource_model);
            $modelDir = str_replace('\\', '_', $modelDir[1]);
            $modelDir = ltrim($modelDir, '_');
            $modelDir = strtolower($modelDir);

            $this->currentUploadDir = $this->uploadDir.DIRECTORY_SEPARATOR.$modelDir.
                DIRECTORY_SEPARATOR.$this->resource_id.DIRECTORY_SEPARATOR;
        }

        return $this->currentUploadDir;
    }

    /**
     * @param string $key
     * @param mixed $value
     * @return $this
     */
    public function setAttribute($key, $value)
    {
        parent::setAttribute($key, $value);

        if ($key == 'meta') {
            $this->attachMetaAttributes([$key => $value]);
        }

        return $this;
    }

    /**
     * @param array $attributes
     * @param bool $sync
     * @return $this
     */
    public function setRawAttributes(array $attributes, $sync = false)
    {
        $this->attachMetaAttributes($attributes);
        parent::setRawAttributes($attributes, $sync);


        return $this;
    }

    /**
     * @param array $attributes
     * @throws \Exception
     */
    protected function attachMetaAttributes(array $attributes)
    {
        if (array_key_exists('meta', $attributes)) {

            // add meta attributes.
            if (is_string($attributes['meta'])) {
                $attributes['meta'] = json_decode($attributes['meta'], true);
            }

            if (! is_array($attributes['meta']) && is_null($attributes['meta'])) {
                $attributes['meta'] = [];
            }

            // Check if fields don't intersect with main model.
            $this->checkMetaFieldCollision(array_keys($attributes['meta']));
            foreach ($attributes['meta'] as $key => $attribute) {
                $this->meta[$key] = $attribute;
            }
        }
    }

    /**
     * @param array $fields
     * @return bool
     * @throws ImageFieldCollisionException
     */
    public function checkMetaFieldCollision(array $fields)
    {
        if ($intersect = array_intersect($this->getDBColumns(), $fields)) {
            throw new ImageFieldCollisionException('Image metadata field/s ('.implode(', ', $intersect).
                ') intersects with main model');
        }

        return false;
    }

    /**
     * @return array|mixed
     */
    public function getDBColumns()
    {
        if (! isset($this->dbColumns)) {
            // Check the cache
            if ($dbColumns = \Cache::get($this->cacheKey.'_db_columns')) {
                $this->dbColumns = $dbColumns;
            } else {
                $this->dbColumns = \Schema::getColumnListing($this->getTable());
                \Cache::put($this->cacheKey.'_db_columns', \Schema::getColumnListing($this->table), 10080);
            }
        }

        return $this->dbColumns;
    }

    /**
     * @param $key
     * @return mixed|null
     */
    public function getMeta($key)
    {
        return isset($this->meta[$key]) ? $this->meta[$key] : null;
    }

    /**
     * Override getter so we can fetch metadata from properties.
     * @param string $key
     * @return mixed
     */
    public function __get($key)
    {
        $value = parent::__get($key);

        // If we don't have a value try to find it in metadata
        if (is_null($value) && $key != 'meta') {
            if (isset($this->meta[$key])) {
                $value = $this->meta[$key];
            }
        }

        return $value;
    }
}
