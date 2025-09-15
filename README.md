# WHMCS One Click App Installation for Contabo Servers

This project enables One Click App installation in WHMCS for Contabo servers using `code init` functionality.

## Overview

This WHMCS module provides seamless integration with Contabo servers, allowing users to automatically provision and configure applications with a single click through the WHMCS interface.

## Features

- **One Click Installation**: Deploy applications instantly through WHMCS
- **Contabo Integration**: Direct integration with Contabo server infrastructure
- **Automated Setup**: Uses `code init` for streamlined application initialization
- **WHMCS Compatibility**: Fully compatible with WHMCS billing system
- **Server Management**: Automated server provisioning and configuration

## Requirements

- WHMCS installation (version 7.0 or higher)
- Contabo server account with API access
- PHP 7.4 or higher
- cURL extension enabled
- Valid SSL certificate

## Installation

1. **Download the Module**
   ```bash
   git clone [repository-url]
   cd whmcs-contabo-oneclick
   ```

2. **Upload to WHMCS**
   - Upload the module files to your WHMCS installation directory
   - Place files in the appropriate WHMCS modules folder

3. **Configure API Credentials**
   - Navigate to WHMCS Admin Area
   - Go to Setup → Products/Services → Servers
   - Add Contabo server with your API credentials

4. **Activate Module**
   - Enable the One Click App module in WHMCS
   - Configure module settings as needed

## Configuration

### Server Settings

Configure the following in WHMCS:

- **Server Name**: Contabo One Click
- **Hostname**: Your Contabo server IP or domain
- **Username**: Contabo API username
- **Password**: Contabo API password
- **Access Hash**: Contabo API access hash

### Module Settings

- **Default Region**: Select your preferred Contabo region
- **Default OS**: Choose default operating system
- **Auto Setup**: Enable automatic server setup
- **Code Init Path**: Configure path for `code init` command

## Usage

### For Administrators

1. **Create Product**
   - Create a new product in WHMCS
   - Set product type to "Server"
   - Select Contabo server module

2. **Configure One Click Apps**
   - Add available applications
   - Set installation parameters
   - Configure pricing and billing

### For Customers

1. **Order Service**
   - Customer orders the One Click App service
   - Payment is processed through WHMCS

2. **Automatic Provisioning**
   - Server is automatically provisioned on Contabo
   - Application is installed using `code init`
   - Customer receives server details and access information

## Supported Applications

The module supports installation of various applications including:

- WordPress
- Joomla
- Drupal
- Magento
- Custom applications via `code init`

## API Integration

### Contabo API

The module integrates with Contabo's API for:
- Server creation and management
- Resource monitoring
- Billing integration
- Automated scaling

### Code Init Integration

Uses `code init` for:
- Application template initialization
- Dependency management
- Environment configuration
- Automated deployment

## Troubleshooting

### Common Issues

1. **API Connection Failed**
   - Verify Contabo API credentials
   - Check server connectivity
   - Ensure API limits are not exceeded

2. **Code Init Errors**
   - Verify `code init` is properly installed
   - Check application templates
   - Review server permissions

3. **WHMCS Integration Issues**
   - Ensure module is properly installed
   - Check WHMCS logs for errors
   - Verify server configuration

### Log Files

- WHMCS Module Log: `/path/to/whmcs/modules/servers/contabo/logs/`
- Contabo API Log: Check Contabo control panel
- Server Logs: `/var/log/` on provisioned servers

## Development

### Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Submit a pull request

### Testing

```bash
# Run unit tests
phpunit tests/

# Test API integration
php tests/api_test.php

# Test WHMCS integration
php tests/whmcs_test.php
```

## Support

- **Documentation**: [Link to documentation]
- **Issues**: [GitHub Issues](https://github.com/your-repo/issues)
- **Community**: [WHMCS Community Forum](https://forum.whmcs.com/)

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Changelog

### Version 1.0.0
- Initial release
- Basic Contabo integration
- One Click App installation
- WHMCS module integration

## Roadmap

- [ ] Additional application templates
- [ ] Multi-region support
- [ ] Advanced monitoring
- [ ] Automated backups
- [ ] Scaling capabilities

---

**Note**: This module requires proper WHMCS licensing and Contabo server access. Ensure you have the necessary permissions and credentials before installation.
