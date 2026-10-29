DOCKER := docker run --rm -v "$(PWD)":/app -w /app composer:2
DOCKER_HOST := docker run --rm --network host -v "$(PWD)":/app -w /app
PCOV_BOOTSTRAP := apk add --no-cache $$PHPIZE_DEPS >/dev/null && pecl install pcov >/dev/null && docker-php-ext-enable pcov
# The composer:2 image ships neither gd nor imagick; the processor tests skip
# without them. This bootstrap compiles both so `make test-images` runs the
# full suite locally, the way CI does.
IMAGES_BOOTSTRAP := apk add --no-cache libpng-dev libjpeg-turbo-dev libwebp-dev freetype-dev libavif-dev \
	imagemagick imagemagick-dev ghostscript $$PHPIZE_DEPS >/dev/null 2>&1 \
	&& docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype --with-avif >/dev/null 2>&1 \
	&& docker-php-ext-install gd >/dev/null 2>&1 \
	&& pecl install imagick >/dev/null 2>&1 && docker-php-ext-enable imagick >/dev/null 2>&1

.PHONY: bench build cs cs-fix psalm test test-images mutation rector rector-fix install normalize require-checker \
       test-coverage test-coverage-ci update-deps release-check bc-check audit-package

install:
	$(DOCKER) composer install --no-interaction --no-progress --prefer-dist

bench:
	$(DOCKER) composer bench

build:
	$(DOCKER) composer build

cs:
	$(DOCKER) composer cs

cs-fix:
	$(DOCKER) composer cs:fix

psalm:
	$(DOCKER) composer psalm

test:
	$(DOCKER) composer test

test-images:
	$(DOCKER) sh -lc '$(IMAGES_BOOTSTRAP) && composer test'

test-coverage:
	$(DOCKER) sh -lc '$(IMAGES_BOOTSTRAP) && $(PCOV_BOOTSTRAP) && composer test:coverage'

test-coverage-ci:
	$(DOCKER) sh -lc '$(IMAGES_BOOTSTRAP) && $(PCOV_BOOTSTRAP) && composer test:coverage:ci'

mutation:
	$(DOCKER) sh -lc '$(IMAGES_BOOTSTRAP) && $(PCOV_BOOTSTRAP) && composer mutation'

rector:
	$(DOCKER) composer rector

rector-fix:
	$(DOCKER) composer rector:fix

normalize:
	$(DOCKER) sh -c 'git config --global --add safe.directory /app; composer normalize'

require-checker:
	$(DOCKER) composer require-checker

update-deps:
	$(DOCKER) sh -c 'git config --global --add safe.directory /app; composer update -q; composer normalize'

# composer's release-check chain ends in bc-check, which shells out to git —
# without safe.directory the container's git refuses the bind-mounted repo
# ("dubious ownership") and the whole target dies with exit 128
release-check:
	$(DOCKER) sh -c 'git config --global --add safe.directory "*"; composer release-check'
	$(MAKE) mutation

bc-check:
	$(DOCKER) sh -c 'git config --global --add safe.directory "*"; \
	  LATEST=$$(git describe --tags --abbrev=0 2>/dev/null || true); \
	  if [ -n "$$LATEST" ]; then \
	    composer bc-check -- --from=$$LATEST; \
	  else \
	    echo "No previous tag - skipping BC check"; \
	  fi'

help:
	@echo "Usage: make <target>"
	@echo ""
	@echo "Targets:"
	@echo "  install          composer install"
	@echo "  bench            run benchmarks (Benchmarks suite)"
	@echo "  build            full gate (validate + normalize + cs + psalm + test)"
	@echo "  cs               check code style (dry-run)"
	@echo "  cs-fix           fix code style"
	@echo "  psalm            static analysis"
	@echo "  test             run testo (Unit suite)"
	@echo "  test-images      run tests with gd+imagick compiled in (full suite, no skips)"
	@echo "  test-coverage    run testo with coverage"
	@echo "  test-coverage-ci run testo coverage for CI artifacts"
	@echo "  mutation         mutation testing"
	@echo "  rector           check rector (dry-run)"
	@echo "  rector-fix       apply rector fixes"
	@echo "  normalize        normalize composer.json"
	@echo "  require-checker  check composer dependencies"
	@echo "  update-deps      composer update + normalize"
	@echo "  bc-check         check backward compatibility against latest tag"
	@echo "  release-check    build + rector + bc-check + mutation"

audit-package:
	@if [ -f ../bin/package-audit ]; then bash ../bin/package-audit "$(CURDIR)"; else echo "package-audit: available only inside the monorepo"; fi
