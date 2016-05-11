var gulp = require('gulp');
var batch = require('gulp-batch');
var config = require('../config');


gulp.task('watch', ['build'], function () {
    gulp.watch(config.sass.src, batch(function (events, done) {
        gulp.start('sass', done);
    }));
    gulp.watch(config.js.src, batch(function (events, done) {
        gulp.start('js-app', done);
    }));
    gulp.watch(config.images.src, batch(function (events, done) {
        gulp.start('images', done);
    }));
    gulp.watch(config.fonts.src, batch(function (events, done) {
        gulp.start('fonts', done);
    }));
    gulp.watch(config.jsons.src, batch(function (events, done) {
        gulp.start('jsons', done);
    }));
});
