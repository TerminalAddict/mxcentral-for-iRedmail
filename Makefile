DEPLOY_HOST ?= root@mail.example.com
DEPLOY_PATH ?= /opt/www/mxcentral-for-iRedmail
APP_DIR := mxcentral-for-iRedmail

# Put real deployment values in Makefile.local. That file is ignored by git.
-include Makefile.local

.PHONY: deploy
deploy:
	ssh $(DEPLOY_HOST) 'mkdir -p "$(DEPLOY_PATH)" "$(DEPLOY_PATH)/bootstrap/cache" "$(DEPLOY_PATH)/database" "$(DEPLOY_PATH)/storage/app" "$(DEPLOY_PATH)/storage/framework/cache" "$(DEPLOY_PATH)/storage/framework/cache/data" "$(DEPLOY_PATH)/storage/framework/sessions" "$(DEPLOY_PATH)/storage/framework/views" "$(DEPLOY_PATH)/storage/logs"'
	rsync -az --delete \
		--exclude='.env' \
		--exclude='.phpunit.result.cache' \
		--exclude='/database/*.sqlite*' \
		--exclude='/node_modules/' \
		--exclude='/public/hot' \
		--exclude='/storage/' \
		$(APP_DIR)/ $(DEPLOY_HOST):$(DEPLOY_PATH)/
	ssh $(DEPLOY_HOST) 'chown -R www-data:www-data "$(DEPLOY_PATH)"'
	ssh $(DEPLOY_HOST) 'cd "$(DEPLOY_PATH)" && sudo -u www-data php artisan optimize:clear'
