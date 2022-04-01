========
Overview
========

Requirements
============

#. PHP 7.2.5
#. To use the PHP stream handler, ``allow_url_fopen`` must be enabled in your
   system's php.ini.
#. To use the cURL handler, you must have a recent version of cURL >= 7.19.4
   compiled with OpenSSL and zlib.

.. note::

    Guzzle no longer requires cURL in order to send HTTP requests. Guzzle will
    use the PHP stream wrapper to send HTTP requests if cURL is not installed.
    Alternatively, you can provide your own HTTP handler used to send requests.
    Keep in mind that cURL is still required for sending concurrent requests.


.. _installation:


Installation
============

The recommended way to install Guzzle is with
`Composer <https://getcomposer.org>`_. Composer is a dependency management tool
for PHP that allows you to declare the dependencies your project needs and
installs them into your project.

.. code-block:: bash

    # Install Composer
    curl -sS https://getcomposer.org/installer | php

You can add Guzzle as a dependency using Composer:

.. code-block:: bash

    composer require guzzlehttp/guzzle:^7.0

Alternatively, you can specify Guzzle as a dependency in your project's
existing composer.json file:

.. code-block:: js

    {
      "require": {
         "guzzlehttp/guzzle": "^7.0"
      }
   }

After installing, you need to require Composer's autoloader:

.. code-block:: php

    require 'vendor/autoload.php';

You can find out more on how to install Composer, configure autoloading, and
other best-practices for defining dependencies at `getcomposer.org <https://getcomposer.org>`_.


Bleeding edge
-------------

During your development, you can keep up with the latest changes on the master
branch by setting the version requirement for Guzzle to ``^7.0@dev``.

.. code-block:: js

   {
      "require": {
         "guzzlehttp/guzzle": "^7.0@dev"
      }
   }


Upgrading
=========
The git repository contains an `upgrade guide`__ that details what changed
between the major versions.

__ https://github.com/guzzle/guzzle/blob/master/UPGRADING.md


License
=======

Licensed using the `MIT license <https://opensource.org/licenses/MIT>`_.

    Copyright (c) 2015 Michael Dowling <https://github.com/mtdowling>

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

1. Guzzle utilizes PSR-1, PSR-2, PSR-4, and PSR-7.
2. Guzzle is meant to be lean and fast with very few dependencies. This means
   that not every feature request will be accepted.
3. Guzzle has a minimum PHP version requirement of PHP 7.2. Pull requests must
   not require a PHP version greater than PHP 7.2 unless the feature is only
   utilized conditionally and the file can be parsed by PHP 7.2.
4. All pull requests must include unit tests to ensure the change works as
   expected and to prevent regressions.


Running the tests
-----------------

In order to contribute, you'll need to checkout the source from GitHub and
install Guzzle's dependencies using Composer:

.. code-block:: bash

    git clone https://github.com/guzzle/guzzle.git
    cd guzzle && composer install

Guzzle is unit tested with PHPUnit. Run the tests using the Makefile:

.. code-block:: bash

    make test

.. note::

    You'll need to install node.js v8 or newer in order to perform integration
    tests on Guzzle's HTTP handlers.


Reporting a security vulnerability
==================================

We want to ensure that Guzzle is a secure HTTP client library for everyone. If
you've discovered a security vulnerability in Guzzle, we appreciate your help
in disclosing it to us in a `responsible manner <https://en.wikipedia.org/wiki/Responsible_disclosure>`_.

Publicly disclosing a vulnerability can put the entire community at risk. If
you've discovered a security concern, please email us at
security@guzzlephp.org. We'll work with you to make sure that we understand the
scope of the issue, and that we fully address your concern. We consider
correspondence sent to security@guzzlephp.org our highest priority, and work to
address any issues that arise as quickly as possible.

After a security vulnerability has been corrected, a security hotfix release will
be deployed as soon as possible.
