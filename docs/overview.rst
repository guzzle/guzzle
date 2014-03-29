========
Overview
========

Requirements
============

#. PHP 5.4.0
#. To use the PHP stream adapter, ``allow_url_fopen`` must be enabled in your
   system's php.ini.
#. To use the cURL adapter, you must have a recent version of cURL >= 7.16.2
   compiled with OpenSSL and zlib.

.. note::

    Guzzle no longer requires cURL in order to send HTTP requests. Guzzle will
    use the PHP stream wrapper to send HTTP requests if cURL is not installed.
    Alternatively, you can provide your own HTTP adapter used to send requests.

.. _installation:

Installation
============

The recommended way to install Guzzle is with `Composer <http://getcomposer.org>`_. Composer is a dependency
management tool for PHP that allows you to declare the dependencies your project needs and installs them into your
project.

.. code-block:: bash

    # Install Composer
    curl -sS https://getcomposer.org/installer | php

You can add Guzzle as a dependency using the composer.phar CLI:

.. code-block:: bash

    php composer.phar require guzzlehttp/guzzle:~4

Alternatively, you can specify Guzzle as a dependency in your project's
existing composer.json file:

.. code-block:: js

    {
      "require": {
         "guzzlehttp/guzzle": "4.*"
      }
   }

After installing, you need to require Composer's autoloader:

.. code-block:: php

    require 'vendor/autoload.php';

You can find out more on how to install Composer, configure autoloading, and
other best-practices for defining dependencies at `getcomposer.org <http://getcomposer.org>`_.

Bleeding edge
-------------

During your development, you can keep up with the latest changes on the master
branch by setting the version requirement for Guzzle to ``dev-master``.

.. code-block:: js

   {
      "require": {
         "guzzlehttp/guzzle": "dev-master"
      }
   }

License
=======

Licensed using the `MIT license <http://opensource.org/licenses/MIT>`_.

    Copyright (c) 2014 Michael Dowling <https://github.com/mtdowling>

    Permission is hereby granted, free of charge, to any person obtaining a copy
    of this software and associated documentation files (the "Software"), to deal
    in the Software without restriction, including without limitation the rights
    to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
    copies of the Software, and to permit persons to whom the Software is
    furnished to do so, subject to the following conditions:

    The above copyright notice and this permission notice shall be included in
    all copies or substantial portions of the Software.

    THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
    IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
    FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
    AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
    LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
    OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
    THE SOFTWARE.

Contributing
============

Guidelines
----------

1. Guzzle follows PSR-0, PSR-1, and PSR-2.
2. Guzzle is meant to be lean and fast with very few dependencies.
3. Guzzle has a minimum PHP version requirement of PHP 5.4. Pull requests must
   not require a PHP version greater than PHP 5.4.
4. All pull requests must include unit tests to ensure the change works as
   expected and to prevent regressions.

Running the tests
-----------------

In order to contribute, you'll need to checkout the source from GitHub and
install Guzzle's dependencies using Composer:

.. code-block:: bash

    git clone https://github.com/guzzle/guzzle.git
    cd guzzle && curl -s http://getcomposer.org/installer | php && ./composer.phar install --dev

Guzzle is unit tested with PHPUnit. Run the tests using the vendored PHPUnit
binary:

.. code-block:: bash

    vendor/bin/phpunit

.. note::

    You'll need to install node.js v0.5.0 or newer in order to perform
    integration tests on Guzzle's HTTP adapters.

Reporting a security vulnerability
==================================

We want to ensure that Guzzle is a secure HTTP client library for everyone. If
you've discovered a security vulnerability in Guzzle, we appreciate your help
in disclosing it to us in a `responsible manner <http://en.wikipedia.org/wiki/Responsible_disclosure>`_.

Publicly disclosing a vulnerability can put the entire community at risk. If
you've discovered a security concern, please email us at
security@guzzlephp.org. We'll work with you to make sure that we understand the
scope of the issue, and that we fully address your concern. We consider
correspondence sent to security@guzzlephp.org our highest priority, and work to
address any issues that arise as quickly as possible.

After a security vulnerability has been corrected, a security hotfix release will
be deployed as soon as possible.
