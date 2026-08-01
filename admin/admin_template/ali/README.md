# Ali Admin Template

Modern, responsive, and accessible admin template for SLiMS Library Management System with shadcn-inspired design.

## Features

### Design
- **shadcn-inspired**: Clean, minimalist design following modern UI/UX best practices
- **CSS Variables**: Easy theming with CSS custom properties
- **Consistent Spacing**: Uses a well-defined spacing scale
- **Typography**: Professional typography with Inter font family
- **Color System**: Comprehensive color palette with semantic colors

### Responsive
- **Mobile-First**: Designed for mobile devices first
- **Adaptive Layout**: Optimized for all screen sizes
- **Breakpoints**: 
  - Mobile: < 480px
  - Tablet: 480px - 768px
  - Desktop: 768px - 1024px
  - Large Desktop: > 1024px

### Accessibility
- **WCAG 2.1 AA**: Compliant with web accessibility standards
- **Keyboard Navigation**: Full keyboard support
- **ARIA Labels**: Proper ARIA attributes for screen readers
- **Focus Management**: Visible focus indicators
- **Skip Links**: Skip to main content functionality

### Components
- **Sidebar**: Collapsible, responsive, with smooth animations
- **Header**: Fixed header with user menu and notifications
- **Cards**: Versatile card component with multiple variants
- **Buttons**: Primary, secondary, outline, and icon buttons
- **Forms**: Enhanced form controls with proper styling
- **Tables**: Responsive tables with hover effects
- **Alerts**: Success, warning, danger, and info alerts
- **Badges**: Color-coded badges for status indicators
- **Modals**: Accessible modal dialogs
- **Tooltips**: Helpful tooltips for interactive elements

### Features
- **Dark Mode**: Automatic or manual dark mode with system preference detection
- **Sidebar Collapse**: Toggle sidebar collapse state (persisted in localStorage)
- **Mobile Sidebar**: Slide-in sidebar on mobile devices
- **Loading States**: Smooth loading indicators
- **Lazy Loading**: Images are lazy-loaded for better performance
- **Debounced Events**: Optimized event handlers for performance

## Installation

1. Copy the `ali` folder to your `admin/admin_template/` directory
2. Select "Ali" as your admin template in SLiMS settings
3. Clear your browser cache

## Configuration

Edit the template settings in `tinfo.inc.php`:

```php
return [
    'name' => 'Ali',
    'description' => 'Modern admin template with shadcn-inspired design',
    'version' => '1.0.0',
    'author' => 'Your Name',
    'author_url' => 'https://your-website.com',
    'license' => 'GPL',
    'thumbnail' => 'preview.png',
    'dir' => AWB . 'admin_template',
    'theme' => 'ali',
    'css' => AWB . 'admin_template/ali/style.css',
    'default_color' => '#3b82f6', // Primary color
    'always_show_tracks' => true
];
```

## Customization

### Changing Colors

Edit the CSS variables in `style.css`:

```css
:root {
    --primary: #3b82f6;        /* Primary brand color */
    --primary-light: #60a5fa;   /* Lighter variant */
    --primary-dark: #2563eb;    /* Darker variant */
    --secondary: #64748b;      /* Secondary color */
    --background: #f8fafc;      /* Page background */
    --foreground: #1e293b;      /* Text color */
    --card: #ffffff;           /* Card background */
    --border: #e2e8f0;         /* Border color */
    /* ... and more */
}
```

### Dark Mode

The template automatically detects system preference for dark mode. Users can also toggle dark mode manually. The dark mode state is saved in localStorage.

To customize dark mode colors, modify the `updateDarkModeVariables()` function in `js/main.js`.

### Adding Custom JavaScript

Add your custom JavaScript to `js/main.js` or create new files in the `js/` directory and include them in `index_template.inc.php`.

## Browser Support

- Chrome (latest 2 versions)
- Firefox (latest 2 versions)
- Safari (latest 2 versions)
- Edge (latest 2 versions)
- Opera (latest 2 versions)

## Best Practices Implemented

1. **Semantic HTML**: Proper use of HTML5 elements
2. **CSS Methodology**: BEM-like naming conventions
3. **Mobile-First**: Responsive design starting from mobile
4. **Accessibility**: WCAG 2.1 AA compliance
5. **Performance**: Optimized assets and lazy loading
6. **Progressive Enhancement**: Works without JavaScript
7. **Cross-Browser Compatibility**: Tested across major browsers
8. **Maintainability**: Well-organized and documented code

## File Structure

```
ali/
├── index_template.inc.php    # Main template file
├── index_template.html       # Preview page
├── style.css                 # Main stylesheet
├── tinfo.inc.php              # Template information
├── preview.png               # Preview image
├── README.md                 # Documentation
├── css/                      # Additional CSS files
├── js/                       # JavaScript files
│   ├── main.js               # Main JavaScript
│   ├── smooth-scrollbar.js   # Scrollbar plugin
│   └── overscroll.js         # Overscroll plugin
├── fonts/                    # Custom fonts (if needed)
├── media/                    # Media files
└── vendor/                   # Third-party libraries
```

## Troubleshooting

### Sidebar not working
- Ensure jQuery is loaded before the template JavaScript
- Check for JavaScript errors in the browser console
- Verify that the sidebar HTML structure is correct

### Styles not applying
- Clear your browser cache
- Check that the CSS file path is correct in `tinfo.inc.php`
- Verify that the template is selected in SLiMS settings

### Dark mode not working
- Check that localStorage is not disabled in your browser
- Ensure the `updateDarkModeVariables()` function is called
- Verify CSS variables are being updated

## License

This template is released under the GPL license, the same as SLiMS.

## Credits

- Design inspired by [shadcn/ui](https://ui.shadcn.com/)
- Icons from [Font Awesome](https://fontawesome.com/)
- Fonts from [Google Fonts](https://fonts.google.com/)

## Changelog

### v1.0.0 (2026-08-01)
- Initial release
- Complete template with all components
- Responsive design
- Dark mode support
- Accessibility features
- Performance optimizations
