Guzzle Iterator
===============

[![Build Status](https://secure.travis-ci.org/guzzle/iterator.png?branch=master)](http://travis-ci.org/guzzle/guzzle)

Component library that provides useful Iterators and Iterator decorators

- ChunkedIterator: Pulls out chunks from an inner iterator and yields the chunks as arrays
- FilterIterator: Used when PHP 5.4's CallbackFilterIterator is not available
- MapIterator: Maps values before yielding
- MethodProxyIterator: Proxies missing method calls to the innermost iterator

### Installing via Composer

The recommended way to install is through [Composer](http://getcomposer.org).

1. Add ``guzzle/iterator`` as a dependency in your project's ``composer.json`` file:

        {
            "require": {
                "guzzle/iterator": "*"
            }
        }

    Consider tightening your dependencies to a known version when deploying mission critical applications (e.g. ``2.7.*``).

2. Download and install Composer:

        curl -s http://getcomposer.org/installer | php

3. Install your dependencies:

        php composer.phar install

4. Require Composer's autoloader

    Composer also prepares an autoload file that's capable of autoloading all of the classes in any of the libraries that it downloads. To use it, just add the following line to your code's bootstrap process:

        require 'vendor/autoload.php';

You can find out more on how to install Composer, configure autoloading, and other best-practices for defining dependencies at [getcomposer.org](http://getcomposer.org).
