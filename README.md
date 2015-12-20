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

composer require wildphp/module-factoids

That will install all required files for the module. In order to activate the module, add the following line to your `main.modules` file:

    WildPHP\Modules\Factoids\Factoids

The bot will run the module the next time it is started.

## Usage
For standard users, the following commands are available:

    - lsfactoids (list factoids in current channel)
    - lsglobalfactoids (list factoids not bound to a specific channel)
    
Standard users may also invoke any factoid.
    
Authenticated users have access to the following commands:

    - setfactoid [key] [value] (set a factoid for the current channel)
    - setglobalfactoid [key] [value] (set a factoid not bound to a specific channel)
    - delfactoid [key] (remove a factoid for the current channel)
    - delglobalfactoid [key] (remove a factoid not bound to a specific channel)

Examples:

Regular, channel-specific usage:
```
<user> !setfactoid myfactoid This is a factoid.
<bot> Factoid 'myfactoid' created for this channel.
<user> !myfactoid
<bot> This is a factoid.
```
Factoids can be triggered using every method that can be used to trigger regular commands.

Global usage:
```
<user> !setglobalfactoid myfactoid This is a factoid.
<bot> Global factoid 'myfactoid' created.
<user> !myfactoid
<bot> This is a factoid.
```

Overriding global factoids:
```
<user> !setglobalfactoid myfactoid This is a global factoid.
<bot> Global factoid 'myfactoid' created.
<user> !setfactoid myfactoid This is a channel-bound factoid.
<bot> Factoid 'myfactoid' created for this channel.
<user> !myfactoid
<bot> This is a channel-bound factoid.
```
Channel-bound factoids will **always** override global factoids of the same name.

## Saving factoids
The module will automatically save factoid state in JSON format, to a file called `factoids.json` in the directory of the module.
These factoids will be restored on the next startup.


## License
This module is licensed under the GNU General Public License, version 3. Please see `LICENSE` to read it.
