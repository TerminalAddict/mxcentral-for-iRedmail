APP_DIR := mxcentral-for-iRedmail

# Put per-server values in ignored Makefile.local. There are deliberately no
# deployable host/path defaults.
-include Makefile.local

.PHONY: deploy
deploy:
	@test -n "$(DEPLOY_HOST)" || { echo "DEPLOY_HOST must be set in Makefile.local or the environment" >&2; exit 64; }
	@test -n "$(DEPLOY_PATH)" || { echo "DEPLOY_PATH must be set in Makefile.local or the environment" >&2; exit 64; }
	APP_USER="$(or $(APP_USER),www-data)" \
	APP_GROUP="$(or $(APP_GROUP),www-data)" \
	SERVER_ENV_FILE="$(SERVER_ENV_FILE)" \
	PRIVILEGED_CONFIG_FILE="$(PRIVILEGED_CONFIG_FILE)" \
	scripts/deploy-rsync.sh "$(DEPLOY_HOST)" "$(DEPLOY_PATH)"
