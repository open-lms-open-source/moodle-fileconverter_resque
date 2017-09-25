# Plugin for converting files with unoconv and resque

This plugin converts files using unoconv, but rather than assuming that the front end servers will have unoconv, it uses redis to queue conversions for worker machines to pickup and actually convert.

## Setup
See the descriptions on the settings page for details about individual settings.

For timeouts, requeueing should be safe, and has minimal impact. It is requeued under the same conversion ID, so whatever request happens first will convert, and any future requests will quickly terminate.


## Worker
There is an included worker in the resqueworker directory. This allows you to setup a listening workers that will process jobs, assuming you don't already have such an infrastructure.

See the README in that directory for more info.


## Unit testing
To use unit testing, you must set the Redis server address:
```php
$CFG->phpunit_fileconverter_resque_server = "localhost:6379";
```
and optionally:
```php
$CFG->phpunit_fileconverter_resque_unoconv_path = "/usr/bin/unoconv";
```


## Composer updates

If you need to update composer, do:
```
composer update --prefer-dist --no-dev
```
This is because the php-resque package has not recent releases, and we don't want composer to install a git repo, which won't add to our git.

