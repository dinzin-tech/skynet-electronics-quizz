const gulp = require('gulp');
const sass = require('gulp-sass')(require('sass'));
const cleanCSS = require('gulp-clean-css');
const terser = require('gulp-terser');

// Compile Sass and Minify CSS
function compileSass() {
  return gulp.src('assets/scss/*.scss')
    .pipe(sass().on('error', sass.logError))
    .pipe(cleanCSS())
    .pipe(gulp.dest('public/assets/css'));
}

// Minify JavaScript
function minifyJS() {
  return gulp.src('assets/js/*.js')
    .pipe(terser())
    .pipe(gulp.dest('public/assets/js'));
}

// Watch Task
function watchFiles() {
  gulp.watch('assets/scss/**/*.scss', compileSass);
  gulp.watch('assets/js/**/*.js', minifyJS);
}

// Define Gulp tasks
exports.default = gulp.series(compileSass, minifyJS);
exports.watch = gulp.series(compileSass, minifyJS, watchFiles);
