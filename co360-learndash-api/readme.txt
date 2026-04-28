=== CO360 LearnDash API ===
Contributors: co360
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4.33
Stable tag: 1.0.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Custom REST API endpoint to query LearnDash enrollments and list course students for corporate integrations. Authentication via API Key header (or `api_key` query param for GET testing).

== Description ==
This plugin exposes a secure REST endpoint that returns LearnDash enrollment information for a specific student or lists all students enrolled in a course.

== Installation ==
1. Upload the `co360-learndash-api` folder to the `/wp-content/plugins/` directory or install the ZIP through the WordPress admin.
2. Activate the plugin through the "Plugins" menu in WordPress.
3. Edit `co360-learndash-api.php` to set a strong API key in the `CO360_LD_API_KEY` constant.

== Usage ==
Endpoint: `POST /wp-json/co360/v1/learndash/enrollment`
Debug/testing: `GET /wp-json/co360/v1/learndash/enrollment`

Headers:
- `X-API-KEY`: Your configured API key.
GET fallback: `api_key` query string parameter.

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

GET examples for testing:

`/wp-json/co360/v1/learndash/enrollment?course_id=123&api_key=XXXX`

`/wp-json/co360/v1/learndash/enrollment?course_id=123&email=student@example.com&api_key=XXXX`

== Changelog ==
= 1.0.4 =
* Improved course roster generation by merging direct course access users with LearnDash completion/activity records and group-based course users when available.

= 1.0.3 =
* Changed human date format to YYYY-MM-DD (kept Unix timestamps).

= 1.0.2 =
* Added enrollment and completion dates (timestamp and d/m/Y format).

= 1.0.1 =
* Fix roster "enrolled" flag to reflect access list users.

= 1.0.0 =
* Initial release with enrollment check and course roster endpoint.
