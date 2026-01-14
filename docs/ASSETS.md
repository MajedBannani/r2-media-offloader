# Plugin Assets Documentation

## Overview

This document explains the WordPress.org plugin assets required for the Media Offloader for CF R2 plugin submission.

## Asset Files

### 1. Plugin Icon (`assets/icon.svg`)

**Purpose**: The plugin icon displayed in the WordPress.org plugin directory.

**File**: `assets/icon.svg`

**Requirements**:
- Format: SVG
- Dimensions: 256x256 pixels (viewBox)
- Square aspect ratio
- Should represent the plugin's purpose
- Must be GPL-compatible

**Current Status**: 
- ✅ Icon created (SVG format)
- Design: Cloud icon with arrow and storage disk
- Colors: Cloudflare orange (#F38020) with white accents
- Minimal, professional design

**To Replace**:
1. Edit `assets/icon.svg` with your preferred design
2. Maintain 256x256 viewBox
3. Ensure GPL-compatible licensing
4. Test display at various sizes

### 2. Plugin Banners

**Purpose**: Banner images displayed on the plugin's WordPress.org page.

**Files Required**:
- `assets/banner-772x250.png` - Standard banner
- `assets/banner-1544x500.png` - High-DPI banner

**Requirements**:
- Format: PNG (24-bit RGB)
- Standard banner: Exactly 772x250 pixels
- High-DPI banner: Exactly 1544x500 pixels (2x resolution)
- File size: Optimized for web (under 1MB each recommended)
- No transparency: Solid backgrounds only
- GPL-compatible licensing

**Design Guidelines**:
- **Theme**: CF R2, media storage, CDN, optimization
- **Style**: Minimal, clean, professional
- **Text**: Optional, keep minimal ("Media Offloader for CF R2")
- **Colors**: 
  - Primary: CF orange (#F38020) - use sparingly
  - Background: Light, neutral colors
  - Text: Dark, high contrast
- **No excessive gradients**: Keep design clean
- **No copyrighted logos**: Without proper licensing

**Current Status**:
- ⚠️ Banner files not included (placeholders needed)
- See `assets/BANNER_README.md` for creation guidelines

**To Create Banners**:

1. **Using Design Software**:
   - Create 772x250 canvas for standard banner
   - Create 1544x500 canvas for high-DPI banner
   - Design following guidelines above
   - Export as PNG (RGB, no transparency)

2. **Using Online Tools**:
   - Canva: https://www.canva.com
   - Figma: https://www.figma.com
   - GIMP: Free, open-source alternative

3. **Hiring a Designer**:
   - WordPress.org has approved designers
   - Ensure assets are GPL-compatible

4. **Place Files**:
   - Save as `assets/banner-772x250.png`
   - Save as `assets/banner-1544x500.png`
   - Ensure exact dimensions

## WordPress.org Asset Rules

### General Requirements

1. **GPL Compatibility**: All assets must be GPL-compatible
2. **Original Work**: Use original designs or properly licensed content
3. **No Copyrighted Material**: Don't use copyrighted logos without permission
4. **No Tracking**: Assets must not include tracking pixels or beacons
5. **No External Resources**: Assets must be self-contained (no external fonts, images, etc.)

### Icon Specific Rules

- Must be SVG format
- 256x256 pixels viewBox
- Square aspect ratio
- Should be recognizable at small sizes
- No text (or minimal text that's readable at small sizes)

### Banner Specific Rules

- Must be PNG format (24-bit RGB)
- Exact dimensions required (772x250 and 1544x500)
- No transparency
- Should be readable and attractive
- Text should be minimal and clear
- Must represent the plugin accurately

## File Structure

```
r2-media-offloader/
├── assets/
│   ├── icon.svg                    # Plugin icon (✅ Created)
│   ├── banner-772x250.png         # Standard banner (⚠️ Needs creation)
│   ├── banner-1544x500.png        # High-DPI banner (⚠️ Needs creation)
│   └── BANNER_README.md           # Banner creation guide
└── docs/
    └── ASSETS.md                   # This file
```

## Testing Assets

Before WordPress.org submission:

1. **Icon Testing**:
   - View at 128x128 pixels
   - View at 64x64 pixels
   - View at 32x32 pixels
   - Ensure it's recognizable at all sizes

2. **Banner Testing**:
   - Verify exact dimensions (772x250 and 1544x500)
   - Check file size (optimize if needed)
   - View on different screen sizes
   - Ensure text is readable
   - Verify colors are appropriate

## Replacing Placeholders

### To Replace Icon

1. Create or obtain an SVG icon (256x256 viewBox)
2. Ensure GPL-compatible licensing
3. Replace `assets/icon.svg`
4. Test at various sizes

### To Replace Banners

1. Create banner designs following guidelines
2. Export as PNG (exact dimensions)
3. Optimize file size
4. Place files:
   - `assets/banner-772x250.png`
   - `assets/banner-1544x500.png`
5. Test display

## Resources

- **WordPress.org Plugin Directory Guidelines**: https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/
- **SVG Icon Guidelines**: https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/#plugin-icon
- **Banner Guidelines**: https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/#plugin-banner

## License

All assets must be:
- GPL v2 or later compatible
- Original work or properly licensed
- Free from copyright restrictions

## Support

For questions about WordPress.org asset requirements:
- WordPress.org Plugin Directory: https://wordpress.org/plugins/developers/
- Plugin Review Team: https://make.wordpress.org/plugins/
