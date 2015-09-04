# Wordpress installer

This package works as an installer for the Wordpress CMS.

## Installation
Download the Wordpress installer via Composer:
```
composer global require cmandersen/wordpress-installer
```

Make sure to place the `~/.composer/vendor/bin` directory in your PATH so the `wordpress` executable can be located by your system.

## Usage
Once installed, the simple `wordpress new` command will create a fresh Wordpress installation in the directory you specify. For instance, `wordpress new blog` will create a directory named blog containing a fresh Wordpress installation.

```
wordpress new blog
```
