=== Plugin Dependencies ===
Contributors: scribu
Donate link: http://scribu.net/wordpress
Tags: plugin, dependency
Requires at least: 3.0
Tested up to: 3.1-alpha
Stable tag: trunk

Plugin dependency management

== Description ==

This meta-plugin allows regular plugins to define other plugins that they depend upon.

**Defining dependencies**

Example:

`
/*
Plugin Name: Child Plugin
Dependencies: parent-plugin/parent-plugin.php another-plugin.php
*/
`

What this does:

* Disables activation of *Child Plugin* until both *Parent Plugin* and *Another Plugin* are already activated.
* When either *Parent Plugin* or *Another Plugin* are deactivated, *Child Plugin* will also be deactivated.

Clarifications:

* Dependencies are separated by spaces.
* Each dependency is represented by a plugin basename.
* A parent plugin can have dependencies of it's own.

Links: [Plugin News](http://scribu.net/wordpress/plugin-dependencies) | [Author's Site](http://scribu.net)

== Screenshots ==

1. Activation prevention
2. Cascade deactivation

== Installation ==

You can either use the built-in installer or do it manually:

1. Unzip the "Plugin Dependencies" archive and put the folder into your plugins folder (/wp-content/plugins/).
1. Activate the plugin from the Plugins menu.

== Frequently Asked Questions ==

= Error on activation: "Parse error: syntax error, unexpected..." =

Make sure your host is running PHP 5. The only foolproof way to do this is to add this line to wp-config.php (after the opening `<?php` tag):

`var_dump(PHP_VERSION);`
<br>

== Changelog ==

= 1.0 =
* initial release
* [more info](http://scribu.net/wordpress/plugin-dependencies/pd-1-0.html)

