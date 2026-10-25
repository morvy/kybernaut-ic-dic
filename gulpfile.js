'use strict';

// Dependencies
var gulp = require('gulp');
var minify = require('gulp-minify');
var babel = require('gulp-babel');
var autoprefixer = require('gulp-autoprefixer');
var cleanCSS = require('gulp-clean-css');
var gcmq = require('gulp-group-css-media-queries');

// Handle JS
gulp.task("scripts", function (done) {
    gulp.src('src/js/[^_]*.js')
        .on('error', console.log)
        .pipe(babel({
            presets: [
                '@babel/preset-env',
            ]
        }))
        .pipe(minify({
            ext: {
                src: '.js',
                min: '.min.js'
            }
        }))
        .pipe(gulp.dest('assets/js/'));
    done();
});

// Minify CSS
gulp.task('styles', function (done) {
    gulp.src(['src/css/*.css'])
        .pipe(autoprefixer({
            cascade: false
        }))
        .pipe(gcmq())
        .pipe(cleanCSS({}))
        .pipe(gulp.dest('assets/css/'))
    done();
});


/**
 * Complete build task
 */
 gulp.task('build', gulp.series('styles', 'scripts'));
