.PHONY: install test bdd coverage stan lint

# A máquina de desenvolvimento não tem PHP: tudo roda em container (scripts/php.sh).
install:
	scripts/composer.sh install

test:
	scripts/php.sh vendor/bin/phpunit --no-coverage

bdd:
	scripts/php.sh vendor/bin/behat --no-colors

coverage:
	scripts/php.sh sh -c "vendor/bin/phpunit --coverage-clover coverage.xml && php bin/check-coverage.php coverage.xml 80"

stan:
	scripts/php.sh vendor/bin/phpstan analyse --no-progress --memory-limit=512M

lint:
	scripts/php.sh sh -c "PHP_CS_FIXER_IGNORE_ENV=1 vendor/bin/php-cs-fixer fix --dry-run --diff"
