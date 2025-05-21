# media-remote-ftp
 upload wordpress media to download server with ftp
 A lightweight WordPress plugin to automatically serve media files (images, video, doc, etc) from a remote download host instead of your main hosting server.

## ✅ Features
- Automatically serves WordPress images from a separate download host
- Reduces load on your main web hosting
- Easy to configure with basic FTP and URL settings
- Supports displaying images from remote source seamlessly

## 📦 Installation
1. Clone or download the plugin:
   git clone https://github.com/mehdi1413/media-remote-ftp.git

2. Upload the plugin folder to your `wp-content/plugins/` directory.
3. Go to the WordPress admin panel → Plugins → Activate the plugin.

## ⚙️ Configuration
1. Open the main plugin PHP file.
2. Set your remote host information by editing the following properties:

```php
protected $base_url = 'https://example.com/uploads'; // Your remote image base URL
protected $ftp_server = 'YOUR_FTP_SERVER_ADDRESS'; // FTP host or IP address
protected $ftp_user   = 'YOUR_FTP_USERNAME';
protected $ftp_pass   = 'YOUR_FTP_PASSWORD';
```

3. Save the file and you're done! Your WordPress will now serve media from the remote host.

## 💡 Notes
- Make sure your FTP user has write permissions to the remote `uploads` directory.
- Use an FTP server with passive mode enabled if behind a firewall.
- Recommended to organize uploads by year/month to avoid clutter.

## 📜 License
This plugin is open-sourced under the MIT License.

## 🙋 Support
Found a bug or need help? Open an issue at:
https://github.com/mehdi1413/media-remote-ftp/issues