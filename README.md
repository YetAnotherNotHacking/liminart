# Pixel Canvas

A collaborative pixel canvas application where users can place colored pixels on a shared canvas.

## Setup Instructions

### Database Configuration

1. Edit the `.env.php` file to set your database credentials:
   ```php
   define('DB_HOST', 'localhost'); // Change if needed
   define('DB_NAME', 'pxllat');    // Your database name
   define('DB_USER', 'root');      // Your MySQL username
   define('DB_PASS', '');          // Your MySQL password
   ```

2. Set up the database by either:
   - Clicking the 🔧 (Setup) button in the application and entering the admin password (`pixeladmin`)
   - Running the SQL script directly with MySQL: `mysql -u your_username -p < setup.sql`
   - Using a tool like phpMyAdmin to run the SQL commands from the `setup.sql` file

3. The application will attempt to create the database and tables automatically if they don't exist, but it's recommended to set them up manually first.

### Canvas Configuration

You can adjust canvas settings in `.env.php`:
```php
define('CANVAS_WIDTH', 1000);  // Canvas width in pixels
define('CANVAS_HEIGHT', 1000); // Canvas height in pixels
define('TILE_SIZE', 32);       // Size of each tile (for loading chunks)
```

### Rate Limiting

You can enable rate limiting to prevent users from placing too many pixels:
```php
define('RATE_LIMIT_SECONDS', 0); // Set to a number of seconds (e.g., 5) to enable rate limiting
```

## Usage

1. Open `index.html` in your browser to view and interact with the canvas.
2. Select a color from the palette, or use the custom color picker to choose any color.
3. The currently selected color is displayed prominently in the top-left corner.
4. Click on the canvas to place a pixel.
5. Use WASD keys or drag with the mouse to navigate the canvas.
6. Use the mouse wheel to zoom in and out.
7. The 🔧 (Setup) button provides access to the database setup page.
8. The 🔍 (Debug) button provides server monitoring information.

## Features

### Color Selection

The application offers two ways to select colors:
1. **Predefined Color Palette**: Click on any of the 15 predefined colors in the toolbar.
2. **Custom Color Picker**: Use the color input control to select any custom color, then click the "+" button to use it.

The current color is always displayed in the left section of the toolbar, showing both a color preview and the hexadecimal color code.

### Real-time Collaborative Canvas

The application provides a high-performance real-time collaborative experience where:
- Pixels placed by any user are near-instantly synced to all other users viewing the canvas
- The canvas uses a checksum-based synchronization system to efficiently detect and update changed tiles
- Each tile has a unique checksum that changes when its content changes
- The client sends its known checksums to the server, which only sends back tiles that have changed
- Updates occur every second, ensuring minimal latency between users
- Updated tiles are highlighted briefly to show where changes have occurred

This efficient synchronization system allows multiple users to work on pixel art together with minimal delay between updates, even on slower connections.

## Technical Implementation

### Checksum-based Synchronization

The application uses a checksum-based approach to efficiently synchronize tiles between clients:

1. **Tile Checksums**: Each 32×32 pixel tile has a unique checksum calculated on the server
2. **Client-Side Tracking**: Clients track the checksums of tiles they've loaded
3. **Efficient Updates**: During update cycles, clients send their known checksums to the server
4. **Selective Refreshing**: The server only sends back complete tiles when their checksums differ from the client's version
5. **Partial Updates**: For minor changes, individual pixel updates are sent instead of complete tiles
6. **Conflict Resolution**: If a checksum mismatch occurs during pixel placement, the client automatically refreshes the tile

This approach significantly reduces bandwidth usage and improves responsiveness, especially when many users are editing the canvas simultaneously.

### Server-Side Implementation (✓ Implemented)

The checksum-based synchronization system is fully implemented in the backend:

1. **`get_state.php`** now supports:
   - Processing POST requests with client tile checksums
   - Calculating MD5 checksums for each tile based on its pixel data
   - Comparing client checksums with server calculations
   - Returning complete tile data only for tiles with different checksums
   - Including checksums in responses for future comparisons
   - Supporting batch verification of multiple tile checksums via `verify_checksums=1`

2. **`set_pixel.php`** now supports:
   - Validating client-provided checksums before pixel placement
   - Returning `checksum_mismatch` errors when needed
   - Including the updated checksum after successful pixel placement

The checksum calculation is identical across both files, ensuring consistency:
```php
function calculateTileChecksum($tileX, $tileY, $tileSize, $pdo) {
    // Gets pixel data from database
    // Creates a string representation of all pixels
    // Returns MD5 hash of the pixel data
}
```

To improve synchronization further, you could add a `checksum` column to the `pixels` table to cache checksums, although this isn't required for the system to work.

## Administrative Features

### Database Setup (Protected)

The database setup page (`db_setup.php`) is password-protected to prevent unauthorized access. 
The default admin password is `pixeladmin`. From this page, you can:

- Update database credentials
- Create/set up the database and tables
- Reset all pixel data
- View database connection status

### Monitoring

The monitor endpoint (`monitor.php`) provides diagnostic information about:

- PHP version and environment
- Database connection status
- Pixel statistics (count, recent activity)
- File system status
- Canvas configuration

## Files

- `index.html` - Main application interface
- `get_state.php` - PHP script for retrieving the current state of the canvas
- `set_pixel.php` - PHP script for placing pixels on the canvas
- `monitor.php` - Monitoring endpoint for server status
- `db_setup.php` - Database setup script (password protected)
- `.env.php` - Environment configuration
- `setup.sql` - SQL script for setting up the database manually

## Troubleshooting

If you encounter issues:

1. Check that your database credentials are correct in `.env.php`
2. Ensure your web server has write permissions to the project directory
3. Check the server logs for PHP errors
4. Try accessing the monitoring endpoint by clicking the 🔍 button
5. Run the database setup by clicking the 🔧 button

### Checksum Synchronization Issues

If the checksum-based updates aren't working properly:

1. Make sure your PHP version supports JSON POST requests (PHP 7.0+)
2. Check server logs for any errors related to checksum calculation
3. Verify that both `get_state.php` and `set_pixel.php` have the same checksum function
4. Try manually accessing the verify_checksums endpoint by visiting `get_state.php?verify_checksums=1`

## Color Palette

The pixel canvas uses a modern color palette with 15 colors:
- Primary colors: orange-red, yellow, green, blue
- Secondary colors: purple, pink, aqua
- Neutrals: black, gray, white

The colors are selected to work well together for pixel art creation. In addition, users can select any custom color using the color picker. 