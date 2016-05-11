var gulp = require('gulp');
var uglify = require('gulp-uglify');
var notify = require('gulp-notify');
var size = require('gulp-filesize');
var cncat = require('gulp-concat');
var jshint = require('gulp-jshint');
var gulpif = require('gulp-if');
var config = require('../config').jsAdmin;
var env = require('gulp-env');

gulp.task('js-admin', function () {
    var isProduction = env.isProduction;

    return gulp.src(config.src)
        .pipe(cncat('admin.js'))
        .pipe(gulpif(isProduction, uglify({
            drop_debugger: true,
            mangle: {
                props: true,
                // toplevel: true,
                eval: true
            }
        })))
        .pipe(gulp.dest(config.dest))
        .pipe(size());
});
