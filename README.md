# LearnDash Email Confirmation

Enhance your LearnDash LMS with email confirmation functionality to secure and streamline user registration. This plugin requires users to confirm their email addresses upon registration, ensuring that only verified users can access course content.

## Features

- **Email Confirmation on Registration**: Sends an automatic email to users upon registration to confirm their email address.
- **Admin Manual Confirmation**: Allows site administrators to manually confirm users directly from the WordPress dashboard.
- **Customizable Email Content**: Tailor the confirmation email content to fit your site’s tone and branding (future feature).
- **Seamless Integration**: Works out of the box with LearnDash, providing a smooth user experience without hindering the registration process.

## Installation

1. Clone this repository or download the ZIP file and extract it.
2. Upload the `learndash-email-confirmation` folder to your WordPress plugin directory: `/wp-content/plugins/`.
3. Navigate to the WordPress admin area and go to the Plugins page.
4. Locate "LearnDash Email Confirmation" in the plugin list and click "Activate".

## Usage

Once activated, the plugin automatically sends a confirmation email to new users who register through LearnDash. The email includes a link that users must click to confirm their email address before they can log in. The link expires after 48 hours (change it with `define('LD_EMAIL_CONFIRMATION_TTL_HOURS', 24);` in `wp-config.php`). Users created by an administrator, via REST, WP-CLI or an import are marked as confirmed automatically and receive no email.

Admins can manually confirm users by navigating to the Users page in the WordPress admin area, where a new column indicates each user's confirmation status along with an option to confirm each user manually.

## Security

See [SECURITY.md](SECURITY.md) to report a vulnerability and [docs/security.md](docs/security.md) for how the plugin is protected and how the automated scans work. Version 1.1.0 fixes a critical authentication bypass, so please update from 1.0.x.

## Contributing

Contributions are welcome! Please feel free to submit a pull request or create an issue for any bugs, improvements, or new features you think would benefit this plugin.

## License

This plugin is licensed under the GPLv2 or later. See the LICENSE file for more details.

## Credits

Developed by Bokis Angelov(https://bokisangelov.com).
