# Changelog

## [2.0.0](https://github.com/aenzenith/laravel-dev-mesh/compare/v1.0.1...v2.0.0) (2026-10-27)


### ⚠ BREAKING CHANGES

* `php artisan dev` becomes `php artisan dev-mesh`. Set DEV_MESH_COMMAND=dev (or `command` in the published config) to keep the old name; already published configs keep their value.

### Features

* rename default command to dev-mesh ([51f5135](https://github.com/aenzenith/laravel-dev-mesh/commit/51f51353bfec4df2dab886d7bc909519a95882d4))
* restart services on code changes, add keyboard controls ([7972c4a](https://github.com/aenzenith/laravel-dev-mesh/commit/7972c4ace6aa274aced4237125776467db85812b))

## [1.0.1](https://github.com/aenzenith/laravel-dev-mesh/compare/v1.0.0...v1.0.1) (2026-09-15)


### Bug Fixes

* **parser:** shorten scheduler command summary lines ([bbe3f0c](https://github.com/aenzenith/laravel-dev-mesh/commit/bbe3f0cec8c14868912132445cb9b3c163522b63))

## 1.0.0 (2026-09-15)


### Features

* initial release ([f431039](https://github.com/aenzenith/laravel-dev-mesh/commit/f431039cda993d469400f1d07f9b8de930c7d94b))
