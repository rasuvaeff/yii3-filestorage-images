# Examples

| Script | Shows | Needs server? |
|---|---|---|
| `derivatives.php` | Lazy generation end to end: filesystem store, one preset, generated on first `ensure()`, reused on the second, sibling object on disk | No (needs ext-gd or ext-imagick; exits cleanly otherwise) |
| `svg-sanitize.php` | What the sanitizer strips (scripts, remote refs, `foreignObject`, `animate`) and what it refuses (non-SVG, element-cap breach) | No |
| `reencode-decorator.php` | A privacy decorator over the core `Storage`: raster originals are re-encoded on `add()` — EXIF stripped, polyglots defeated — while other uploads pass through byte-identical | No (needs ext-gd or ext-imagick; exits cleanly otherwise) |

Install development dependencies and run from the package checkout:

```bash
composer install
php examples/derivatives.php
php examples/svg-sanitize.php
php examples/reencode-decorator.php
```

The `composer:2` image ships without gd/imagick; to run the image-dependent
examples in Docker, reuse the bootstrap the Makefile uses:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 sh -lc \
  'apk add --no-cache libpng-dev libjpeg-turbo-dev libwebp-dev freetype-dev libavif-dev $PHPIZE_DEPS >/dev/null \
   && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype --with-avif >/dev/null \
   && docker-php-ext-install gd >/dev/null \
   && php examples/derivatives.php'
```
