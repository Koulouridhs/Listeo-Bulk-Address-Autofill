How It Works
Settings: API key and pagination settings are stored in the database and retrieved dynamically.
Filters: Dropdown and text input modify the WP_Query to show relevant listings.
Pagination: Loads $per_page listings (configurable), with styled buttons for navigation.
Autofill: Uses cached API data (30-day expiration) to update addresses, minimizing API calls.
Load Time: No API calls on page load; only database queries and parsing, making it fast.
