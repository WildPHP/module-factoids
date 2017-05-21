# Factoids Module
[![Build Status](https://scrutinizer-ci.com/g/WildPHP/module-factoids/badges/build.png?b=master)](https://scrutinizer-ci.com/g/WildPHP/module-factoids/build-status/master)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/WildPHP/module-factoids/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/WildPHP/module-factoids/?branch=master)
[![Latest Stable Version](https://poser.pugx.org/wildphp/module-factoids/v/stable)](https://packagist.org/packages/wildphp/module-factoids)
[![Latest Unstable Version](https://poser.pugx.org/wildphp/module-factoids/v/unstable)](https://packagist.org/packages/wildphp/module-factoids)
[![Total Downloads](https://poser.pugx.org/wildphp/module-factoids/downloads)](https://packagist.org/packages/wildphp/module-factoids)

This module provides basic factoids support for WildPHP. It allows you to store small bits of information to recall later.

## System Requirements
If your setup can run the main bot, it can run this module as well.

## Installation
To install this module, we will use `composer`:

```composer require wildphp/module-factoids```

That will install all required files for the module. In order to activate the module, add the following line to your modules array in `config.neon`:

    - WildPHP\Modules\Factoids\Factoids

The bot will run the module the next time it is started.

## Usage
A target can be either a channel name or `global` (for factoids which exist everywhere).
If no target is specified, most commands assume the current channel.

For standard users, the following commands are available:

* `lsfactoids ([target])`
* `factoidinfo ([target]) [key]`
    
Standard users may also invoke any factoid.
    
Authenticated users have access to the following commands:
* `addfactoid ([target]) [key] [value]`
    * Required permission: `addfactoid`
* `delfactoid ([target]) [key]`
    * Required permission: `delfactoid`
* `editfactoid ([target]) [key] [value]`
    * Required permission: `editfactoid`
* `movefactoid [key] ([source target]) [destination target]`
    * Required permission: `movefactoid`
* `renamefactoid ([target]) [key] [new key]`
    * Required permission: `renamefactoid`

Channel-bound factoids will **always** override global factoids of the same name.

## Saving factoids
The module will automatically save factoid state in JSON format, to a storage named `factoids.dat`
These factoids will be restored on the next startup.

## License
This module is licensed under the GNU General Public License, version 3. Please see `LICENSE` to read it.
