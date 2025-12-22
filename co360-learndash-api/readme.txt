=== CO360 LearnDash API ===
Contributors: co360
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4.33
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Custom REST API endpoint to query LearnDash enrollments and list course students for corporate integrations. Authentication via API Key header.

== Description ==
This plugin exposes a secure REST endpoint that returns LearnDash enrollment information for a specific student or lists all students enrolled in a course.

== Installation ==
1. Upload the `co360-learndash-api` folder to the `/wp-content/plugins/` directory or install the ZIP through the WordPress admin.
2. Activate the plugin through the "Plugins" menu in WordPress.
3. Edit `co360-learndash-api.php` to set a strong API key in the `CO360_LD_API_KEY` constant.

== Usage ==
Endpoint: `POST /wp-json/co360/v1/learndash/enrollment`

Headers:
- `X-API-KEY`: Your configured API key.

Body examples (JSON):

Single student lookup:
```
{
  "email": "student@example.com",
  "course_id": 123
}
```

Course roster:
```
{
  "course_id": 123
}
```

== Changelog ==
= 1.0.0 =
* Initial release with enrollment check and course roster endpoint.
