.PHONY: help install update setup env-file migrate migrate-fresh test clean key-generate optimize clear-cache vk-check vk-groups-info

# Цвета для вывода
GREEN  := $(shell tput -Txterm setaf 2)
YELLOW := $(shell tput -Txterm setaf 3)
RESET  := $(shell tput -Txterm sgr0)

help: ## Показать эту справку
	@echo "$(GREEN)Доступные команды:$(RESET)"
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "  $(YELLOW)%-15s$(RESET) %s\n", $$1, $$2}'

install: ## Установить зависимости Composer
	@echo "$(GREEN)Установка зависимостей...$(RESET)"
	composer install

update: ## Обновить зависимости Composer
	@echo "$(GREEN)Обновление зависимостей...$(RESET)"
	composer update

env-file: ## Создать .env файл из .env.example (если не существует)
	@if [ ! -f .env ]; then \
		echo "$(GREEN)Создание .env файла...$(RESET)"; \
		cp .env.example .env 2>/dev/null || touch .env; \
		echo "$(GREEN)✓ .env файл создан$(RESET)"; \
	else \
		echo "$(YELLOW).env файл уже существует$(RESET)"; \
	fi

setup: env-file install key-generate migrate ## Полная настройка проекта (env + install + key + migrate)
	@echo "$(GREEN)✓ Проект настроен и готов к работе!$(RESET)"
	@echo "$(YELLOW)Не забудьте настроить VK_TOKEN в .env файле!$(RESET)"

key-generate: ## Сгенерировать ключ приложения
	@echo "$(GREEN)Генерация ключа приложения...$(RESET)"
	php artisan key:generate

migrate: ## Запустить миграции базы данных
	@echo "$(GREEN)Запуск миграций...$(RESET)"
	php artisan migrate

migrate-fresh: ## Пересоздать базу данных (удалить все таблицы и запустить миграции заново)
	@echo "$(YELLOW)Внимание: все данные будут удалены!$(RESET)"
	php artisan migrate:fresh

test: ## Запустить тесты
	@echo "$(GREEN)Запуск тестов...$(RESET)"
	php artisan test

clean: clear-cache optimize ## Очистить кеш и оптимизировать приложение
	@echo "$(GREEN)Очистка завершена!$(RESET)"

clear-cache: ## Очистить все кеши
	@echo "$(GREEN)Очистка кешей...$(RESET)"
	php artisan cache:clear
	php artisan config:clear
	php artisan route:clear
	php artisan view:clear

optimize: ## Оптимизировать приложение (кеш конфига, роутов и т.д.)
	@echo "$(GREEN)Оптимизация приложения...$(RESET)"
	php artisan config:cache
	php artisan route:cache

# Команды для работы с VK API
vk-check: ## Проверить последние посты в группах из resources.csv
	php artisan vk:check

vk-groups-info: ## Получить информацию о группах из resources.csv
	php artisan vk:groups:info

# Примеры использования команд VK (можно раскомментировать и настроить)
# vk-posts-get: ## Получить посты за период (пример: make vk-posts-get OWNER=-12345678 FROM=2024-01-01 TO=2024-01-31)
# 	php artisan vk:posts:get --owner=$(OWNER) --from=$(FROM) --to=$(TO)

# vk-posts-get-db: ## Получить посты и сохранить в БД (пример: make vk-posts-get-db OWNER=-12345678 FROM=2024-01-01)
# 	php artisan vk:posts:get --owner=$(OWNER) --from=$(FROM) --db

