/**
 * EGroupware login page javascript
 *
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @package login
 * @subpackage api
 * @link https://www.egroupware.org
 */

/* if login page is not in top window, set top windows location to it */
if (top !== window) top.location = window.location;

egw_LAB.wait(function()
{
	jQuery(document).ready(function()
	{
		// check if the browser supports ES6 and try to warn user
		try {
			Function ("() => {};");
		}
		catch (exception){
			alert(egw.lang('Your browser is not up-to-date (JavaScript ES6 compatible), you may experience some of the features not working.'));
		}

		// lock the device orientation in portrait view
		if (screen.orientation && typeof screen.orientation.lock == 'function') screen.orientation.lock('portrait');
		jQuery('.closeBtn').click(function (){
			setTimeout(function(){jQuery('.loginMessageBox').slideUp("slow");},100);
		});
		function do_social(_data)
		{
			var social = jQuery(document.createElement('div'))
				.attr({
					id: "socialMedia",
					class: "socialMedia"
				})
				 .appendTo(jQuery('#socialBox'));

			for(var i=0; i < _data.length; ++i)
			{
				var data = _data[i];
				var url = (data.lang ? data.lang[jQuery('meta[name="language"]').attr('content')] : null) || data.url;
				jQuery(document.createElement('a')).attr({
					href: url,
					target: '_blank'
				})
				.appendTo(social)
				.append(jQuery(document.createElement('img'))
					.attr('src', data.svg));
			}
		}

		do_social([
			{ "svg": egw_webserverUrl+"/api/templates/default/images/login_contact.svg", "url": "https://www.egroupware.org/en/contact.html", "lang": { "de": "https://www.egroupware.org/de/kontakt.html" }},
			{ "svg": egw_webserverUrl+"/api/templates/default/images/login_facebook.svg", "url": "https://www.facebook.com/egroupware" },
			{ "svg": egw_webserverUrl+"/api/templates/default/images/login_twitter.svg", "url": "https://twitter.com/egroupware" },
			{ "svg": egw_webserverUrl+"/api/templates/default/images/login_discourse.svg", "url": "https://help.egroupware.org" },
			{ "svg": egw_webserverUrl+"/api/templates/default/images/login_github.svg", "url": "https://github.com/EGroupware/egroupware" }
		]);
	});
});
