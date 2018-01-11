=== Plugin Dependencies ===
Contributors: scribu, xwp, kucrut, jrf
Tags: plugin, dependency
Requires at least: 3.1
Tested up to: 4.0
Stable tag: trunk
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Plugin dependency management

== Description ==

This meta-plugin allows regular plugins to specify other plugins that they depend upon.

Example:

<pre lang="php">
/*
 * Plugin Name: BuddyPress Debug
 * Depends: BuddyPress, Debug Bar
 */
</pre>

What this does:

* Disables activation of *BuddyPress Debug* until both *BuddyPress* and *Debug Bar* are already activated.
* When either *BuddyPress* or *Debug Bar* are deactivated, *BuddyPress Debug* will also be deactivated.


> = Enriching dependency information =
>
> Unfortunately, very few plugins currently contain dependency information. If you'd like to enhance the information available to this plugin, you might want to install the [Known Plugin Dependencies](https://wordpress.org/plugins/known-plugin-dependencies/) plugin which acts as an add-on to this one.

**Development of this plugin is done [on GitHub](https://github.com/xwp/wp-plugin-dependencies). Pull requests welcome. Please see [issues](https://github.com/xwp/wp-plugin-dependencies/issues) reported there before going to the plugin forum.**

== Frequently Asked Questions ==

= What happens if a user doesn't have Plugin Dependencies installed? =

Nothing. The *Depends:* header will simply be ignored.

= Can I have grand-child plugins? =

Yes, the dependency chain can go as deep as you want.

= Defining virtual packages =

Say you have some useful functions that you would like to package up as a library plugin:

<pre lang="php">
/*
 * Plugin Name: Facebook Lib
 * Provides: lib-facebook
 */
</pre>

Now, dependant plugins can specify 'lib-facebook' as a dependency:

<pre lang="php">
/*
 * Plugin Name: Cool Facebook Plugin
 * Depends: lib-facebook
 */
</pre>

Besides being more robust, the *Provides:* header allows multiple plugins to implement the same set of functionality and be used interchangeably.

== Screenshots ==

1. Activation prevention
1. Cascade deactivation

== Changelog ==

= 1.3 =
* Add Dependency Loader class. Props [kucrut](http://profiles.wordpress.org/kucrut/).

* Make it work with bulk actions. Props [jrf](http://profiles.wordpress.org/jrf/).
	* Usability: Remove bulk action checkboxes for plugins with unsatisfied dependencies on single site plugins page within a network.

* Guard dependencies even when a plugin is (de)activated outside of the plugins page context. Props [jrf](http://profiles.wordpress.org/jrf/).

* Fix compatibility with multi-site. Props [jrf](http://profiles.wordpress.org/jrf/).
	* New: Show dependencies in the network admin plugins page.
	* Bug fix: network activated plugins were not recognized (at all) and deactivating one would throw PHP notices.
	* Bug fix: network activation action was not correctly unset if dependencies were not met (WP 3.4+).
	* Bug fix: network deactivation would only check dependencies for the network and the main site, not for the other sites in the network
	* Improved: logic for recognizing whether dependencies have been satisfied.
	* Usability: On single site plugin page in a multisite network: added a "network" textual indicator for dependencies which were met by a network activated plugin.
	* Usability: On single site plugin page in a multisite network: the required plugin names now only link to the plugin if the current user can activate that plugin.
	* Usability: Improved information to single site admins when dependent plugins have been deactivated because a required plugin has been network deactivated - show all deactivated plugins since last admin login, not just what happened in the last change round.
	* Usability: Notifications about deactivated plugins are now shown on any admin page which will help admins notice changes made by this plugin earlier in case of a network deactivation.

* Clean up coding standards. Props [kucrut](http://profiles.wordpress.org/kucrut/), [jrf](http://profiles.wordpress.org/jrf/).
* Improve style of plugin dependency notices. Props [jrf](http://profiles.wordpress.org/jrf/).
* Usability: Add plugins deactivated by this plugin to the 'recently active' plugins list. Props [jrf](http://profiles.wordpress.org/jrf/).
* Add Dutch translation. Props [jrf](http://profiles.wordpress.org/jrf/).


= 1.2.1 =
* fixed notices. props [cfoellmann](http://profiles.wordpress.org/cfoellmann)

= 1.2 =
* added ability to use plugin names as dependencies
* [more info](http://scribu.net/wordpress/plugin-dependencies/pd-1-2.html)

= 1.1 =
* added 'Provides:' header
* replaced 'Dependencies:' with 'Depends:'
* [more info](http://scribu.net/wordpress/plugin-dependencies/pd-1-1.html)

= 1.0.1 =
* fixed critical bug when not running MultiSite
* better network activation handling

= 1.0 =
* initial release
* [more info](http://scribu.net/wordpress/plugin-dependencies/pd-1-0.html)

== Upgrade Notice ==

= 1.3 =
* Upgrade highly recommended - Plugin now fully compatible with multisite and dependency management will now also work outside of the plugins page context, including for bulk actions.