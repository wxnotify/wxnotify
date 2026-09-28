-- Copied from vendor/laravel/sail/database/pgsql/create-testing-database.sql:
-- creates the parallel `testing` database Pest's parallel test runner uses,
-- if it doesn't already exist. Kept in-repo (rather than referencing the
-- vendor path directly) because laravel/sail is no longer a dependency of
-- this project -- see docker/8.4/Dockerfile and the compose.yaml services it
-- replaces docker/8.5 for.
SELECT 'CREATE DATABASE testing'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'testing')\gexec
