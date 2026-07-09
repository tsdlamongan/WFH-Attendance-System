# Design System Inspired by Kirim.chat

## 1. Visual Theme & Atmosphere

Kirim.chat embodies a modern, energetic, and approachable design language built for Indonesian business users. The system balances boldness with clarity, using vibrant greens and dynamic interactions to convey productivity, trust, and speed. The aesthetic combines clean minimalism with playful accents—rounded corners, offset shadows, and colorful integration badges create personality while maintaining professionalism. The visual identity emphasizes simplicity and efficiency, reflecting the platform's promise of fast omnichannel CRM setup. A strong reliance on positive, action-oriented green tones paired with warm neutrals creates an inviting atmosphere that resonates with small to medium-sized businesses seeking accessible communication solutions.

**Key Characteristics**
- Bold, offset drop shadows (`4px` / `8px` offsets) for depth and tactile presence
- High-contrast text on clean backgrounds for readability and focus
- Rounded pill-shaped buttons (`9999px` radius) signifying friendliness and approachability
- Vibrant integration colors (WhatsApp green `#25D366`, Instagram pink `#E4405F`, Facebook blue `#0084FF`)
- Generous whitespace and breathing room around content modules
- Dark slate headings (`#1E293B`) paired with warm grays for hierarchy
- Card-based layouts with consistent `2px` borders and strong shadows

## 2. Color Palette & Roles

### Primary

- **Brand Green** (`#047857`): Core brand identity, used sparingly for emphasis and micro-interactions. Represents growth and trust in the omnichannel messaging context.
- **Success Green** (`#22C55E`): Primary call-to-action buttons, active states, and positive confirmations. Dominant color for the platform's main interactions and "Daftar" (Register) button.

### Accent Colors

- **Magenta / Hot Pink** (`#DB2777`): Secondary accent for premium features, highlights, and connection workflows. Used in "Connect" step and feature callouts.
- **Bright Pink** (`#EC4899`): Interactive accents and hover states, creating visual interest and engagement signals.

### Interactive

- **WhatsApp Green** (`#25D366`): Third-party integration indicator for WhatsApp Business API connectivity.
- **Instagram Pink** (`#E4405F`): Third-party integration indicator for Instagram DM channels.
- **Facebook Blue** (`#0084FF`): Third-party integration indicator for Facebook Messenger channels.

### Neutral Scale

- **Slate 900** (`#1E293B`): Primary text, headings, borders, and dark UI elements. High contrast foundation for all interface text.
- **Slate 400** (`#64748B`): Secondary text, helper text, and muted descriptions. Used for body copy and non-critical information.
- **Slate 200** (`#E2E8F0`): Light borders, dividers, and subtle background separations.
- **Off-white** (`#FAFBFC`, `#F1F5F9`): Subtle background variations for depth layering without strong contrast shifts.

### Surface & Borders

- **White** (`#FFFFFF`): Primary card and container backgrounds, ensuring clarity and focus.
- **Slate 900 Border** (`#1E293B`): Consistent `2px` borders across all cards, buttons, and interactive elements, creating the signature outlined aesthetic.

### Semantic / Status

- **Warning Yellow** (`#F59E0B`): Cautionary messages, pending states, and non-critical alerts.
- **Error Red** (`#E11D48`): Error messages, destructive actions, and critical alerts requiring immediate attention.

## 3. Typography Rules

### Font Family

**Primary:** Outfit (sans-serif, geometric)  
Fallback: `system-ui, -apple-system, sans-serif`

**Secondary:** Plus Jakarta Sans (sans-serif, humanist)  
Fallback: `system-ui, -apple-system, sans-serif`

### Hierarchy

| Role | Font | Size | Weight | Line Height | Letter Spacing | Notes |
|------|------|------|--------|-------------|----------------|-------|
| Display / H1 | Outfit | 48px | 700 | 57.6px | 0px | Hero headlines, page titles |
| Heading / H2 | Outfit | 32px | 700 | 38.4px | 0px | Section titles, major headings |
| Heading / H3 | Outfit | 24px | 700 | 28.8px | 0px | Card titles, subsection headers |
| Heading / H4 | Outfit | 18px | 700 | 21.6px | 0px | Feature titles, label emphasis |
| Button / Strong Text | Outfit | 16px | 700 | 24px | 0px | Call-to-action text, bold labels |
| Body | Plus Jakarta Sans | 18px | 400 | 29.25px | 0px | Main content, descriptions |
| Button / UI Text | Plus Jakarta Sans | 16px | 400 | 24px | 0px | Button labels, form inputs |
| List Item / Secondary | Plus Jakarta Sans | 14px | 400 | 20px | 0px | Bullet points, captions, helper text |

### Principles

- **Hierarchy through weight and size:** Outfit (`700` weight) signals importance and action; Plus Jakarta Sans (`400` weight) supports and clarifies.
- **Generous line height:** All typography uses `1.2x` to `1.625x` multipliers for comfortable reading on screens.
- **Consistent spacing:** Typography pairs always include `8px` minimum padding around text containers.
- **Contrast-first approach:** Slate 900 text on white or light backgrounds ensures WCAG AA compliance.
- **Geometric precision:** Outfit's geometric forms reinforce the modern, structured nature of the platform; Plus Jakarta Sans's humanist warmth makes content feel accessible.

## 4. Component Stylings

### Buttons

#### Primary Button (Filled Green)
```
background-color: #22C55E
color: #FFFFFF
font-family: Outfit
font-size: 16px
font-weight: 700
padding: 14px 28px
border-radius: 9999px
border: 2px solid #1E293B
box-shadow: #1E293B 4px 4px 0px 0px
line-height: 24px
transition: all 0.2s ease
```
**Hover State:** `background-color: #16A34A`, shadow increases to `#1E293B 6px 6px 0px 0px`  
**Active State:** `background-color: #15803D`, shadow reduces to `#1E293B 2px 2px 0px 0px`

#### Secondary Button (Outlined)
```
background-color: transparent
color: #1E293B
font-family: Outfit
font-size: 16px
font-weight: 700
padding: 14px 28px
border-radius: 9999px
border: 2px solid #1E293B
box-shadow: none
line-height: 24px
transition: all 0.2s ease
```
**Hover State:** `background-color: #F1F5F9`, `box-shadow: #1E293B 2px 2px 0px 0px`  
**Active State:** `background-color: #E2E8F0`, `box-shadow: none`

#### Ghost Button (Navigation)
```
background-color: transparent
color: #1E293B
font-family: Plus Jakarta Sans
font-size: 16px
font-weight: 400
padding: 20px 24px
border: none
border-radius: 0px
box-shadow: none
line-height: 24px
transition: color 0.2s ease
```
**Hover State:** `color: #64748B`, `background-color: #FAFBFC`  
**Active State:** `color: #047857`, `border-bottom: 3px solid #047857`

### Cards & Containers

#### Feature Card (with offset shadow)
```
background-color: #FFFFFF
color: #1E293B
font-family: Plus Jakarta Sans
font-size: 16px
font-weight: 400
padding: 48px
border: 2px solid #1E293B
border-radius: 16px
box-shadow: #1E293B 8px 8px 0px 0px
line-height: 24px
transition: box-shadow 0.2s ease, transform 0.2s ease
```
**Hover State:** `box-shadow: #1E293B 12px 12px 0px 0px`, `transform: translate(-2px, -2px)`

#### Rounded Card (with larger shadow)
```
background-color: #FFFFFF
color: #1E293B
font-family: Plus Jakarta Sans
font-size: 16px
font-weight: 400
padding: 0px
border: 2px solid #1E293B
border-radius: 32px
box-shadow: #1E293B 8px 8px 0px 0px
line-height: 24px
```

#### Pill Container (Header/Navigation)
```
background-color: rgba(255, 255, 255, 0.95)
color: #1E293B
font-family: Plus Jakarta Sans
font-size: 16px
font-weight: 400
padding: 8px
border: 2px solid #1E293B
border-radius: 9999px
box-shadow: #1E293B 4px 4px 0px 0px
line-height: 24px
```

### Inputs & Forms

#### Text Input / Form Field
```
background-color: #FFFFFF
color: #1E293B
font-family: Plus Jakarta Sans
font-size: 16px
font-weight: 400
padding: 12px 16px
border: 2px solid #1E293B
border-radius: 8px
box-shadow: none
line-height: 24px
transition: border-color 0.2s ease, box-shadow 0.2s ease
```
**Focus State:** `border-color: #047857`, `box-shadow: #047857 0px 0px 0px 3px`  
**Error State:** `border-color: #E11D48`, `background-color: #FEE2E2`

#### Label
```
font-family: Outfit
font-size: 14px
font-weight: 700
color: #1E293B
margin-bottom: 6px
display: block
```

#### Helper Text
```
font-family: Plus Jakarta Sans
font-size: 12px
font-weight: 400
color: #64748B
margin-top: 4px
```

### Navigation

#### Top Navigation Bar
```
background-color: transparent
color: #1E293B
font-family: Plus Jakarta Sans
font-size: 16px
font-weight: 400
padding: 0px
border: none
border-radius: 0px
box-shadow: none
display: flex
align-items: center
gap: 32px
```
**Link Styles:** Inherit navigation text color; underline on hover using `border-bottom: 2px solid #047857`

#### Breadcrumb
```
font-family: Plus Jakarta Sans
font-size: 14px
font-weight: 400
color: #64748B
line-height: 20px
```
**Separator:** ` / ` with `margin: 0px 8px`  
**Active Breadcrumb:** `color: #1E293B`, `font-weight: 600`

### Badges & Tags

#### Integration Badge (WhatsApp, Instagram, Facebook)
```
background-color: brand-specific (#25D366 WhatsApp, #E4405F Instagram, #0084FF Facebook)
color: #FFFFFF
font-family: Outfit
font-size: 12px
font-weight: 700
padding: 4px 12px
border: none
border-radius: 9999px
box-shadow: none
line-height: 16px
display: inline-flex
align-items: center
gap: 6px
```

#### Feature Tag (Premium, Setup, etc.)
```
background-color: #22C55E
color: #FFFFFF
font-family: Outfit
font-size: 12px
font-weight: 700
padding: 6px 14px
border: 2px solid #1E293B
border-radius: 9999px
box-shadow: #1E293B 2px 2px 0px 0px
line-height: 16px
```

## 5. Layout Principles

### Spacing System

**Base Unit:** `4px`

**Scale:**
- Micro: `4px` (internal gap in compact elements)
- Extra Small: `8px` (padding in buttons, tight spacing)
- Small: `12px` (gap between inline elements)
- Medium: `16px` (standard padding, moderate spacing)
- Large: `20px` (gap between sections)
- Extra Large: `24px` (section margins, breathing room)
- XXL: `32px` (large component spacing)
- XXXL: `40px` (major section breaks)
- Huge: `48px` (card padding, large breathing)
- Giant: `64px` (page-level vertical spacing)
- Massive: `80px` (hero section spacing)
- Enormous: `96px` (top-level section padding)

**Usage Context:**
- Button padding: `14px 28px` (medium vertical + large horizontal)
- Card padding: `48px` (huge breathing room for content)
- Section gaps: `40px`–`64px` (ensures clear visual separation)
- Element margins: `8px`–`16px` (tight grouping)
- Page margins: `20px`–`40px` (mobile to desktop scaling)

### Grid & Container

**Max Width:** `1200px` (desktop), `100vw` (mobile/tablet with padding)

**Column Strategy:** 
- Desktop: 12-column grid with `16px` gutters
- Tablet: 8-column grid with `12px` gutters
- Mobile: 1-column stack with `8px` gutters

**Section Patterns:**
- Hero sections: full-width, centered content with max 900px inner width
- Feature grids: 3 columns (desktop) → 2 columns (tablet) → 1 column (mobile)
- Card layouts: uniform `2px` borders, consistent `32px` border-radius on large cards, `16px` on standard cards
- Navigation containers: full-width with internal max-width constraint

### Whitespace Philosophy

Kirim.chat employs **deliberate breathing room** to reduce cognitive load and highlight key actions. Whitespace is not empty—it actively guides attention through negative space. Large padding inside cards (`48px`), generous margins between sections (`40px`–`80px`), and strategic use of off-white backgrounds create visual hierarchy without resorting to visual weight. The `9999px` border-radius on buttons and containers signals action and friendliness, while squared corners on content cards anchor stability.

### Border Radius Scale

- **Pill / Full Round:** `9999px` (buttons, badges, pills, micro-interactions)
- **Large Round:** `32px` (large feature cards, rounded containers)
- **Medium Round:** `24px` (standard cards, medium components)
- **Standard Round:** `16px` (input fields, smaller cards, secondary components)
- **Subtle Round:** `8px` (code blocks, tight components)
- **No Round:** `0px` (navigation bars, full-width sections, borders)

## 6. Depth & Elevation

| Level | Treatment | Use |
|-------|-----------|-----|
| Flat (No Elevation) | `box-shadow: none`, solid `2px` border | Navigation bars, secondary buttons, text content |
| Raised (Subtle) | `box-shadow: #1E293B 2px 2px 0px 0px` | Hover states, focus states, small cards |
| Elevated (Medium) | `box-shadow: #1E293B 4px 4px 0px 0px` | Buttons, small cards, pill containers |
| High (Strong) | `box-shadow: #1E293B 8px 8px 0px 0px` | Feature cards, modal containers, prominent sections |
| Maximum (Extreme) | `box-shadow: #1E293B 12px 12px 0px 0px` | Hover states on major cards, modals, overlays |

**Shadow Philosophy**

Kirim.chat employs a **neo-brutalist shadow approach**—hard, offset drop shadows rather than blur-based shadows. This creates a tactile, playful aesthetic reminiscent of print design while maintaining digital clarity. Shadows use solid `#1E293B` with `0px` blur, providing pixel-perfect, grid-aligned depth. The offset direction is consistently down-right (`4px` / `8px` / `12px` on X and Y axes), creating a sense of forward motion and energy. Shadows increase on interaction to signal responsiveness and delight.

Semantic use of shadows:
- Primary actions (buttons): `4px 4px` offset
- Secondary containers (cards): `8px 8px` offset
- Hover/active states: escalate by `2px`–`4px`
- Nested elements: reduce by `2px`–`4px` to maintain hierarchy

## 7. Do's and Don'ts

### Do

- **Always use `2px` borders** on interactive elements (buttons, cards, inputs) to maintain the outlined aesthetic and consistency.
- **Apply `#1E293B` text** on white or very light backgrounds for optimal contrast and readability.
- **Use `#22C55E` for all primary call-to-action buttons** (Register, Try, Setup, etc.) to establish a consistent action pattern.
- **Maintain `9999px` border-radius** on buttons and pill-shaped containers—this is a signature of the brand.
- **Include offset drop shadows** on cards and elevated elements using the hardline, grid-aligned approach (`4px 4px 0px 0px`, etc.).
- **Stack spacing in multiples of `8px`** for consistency and alignment to an invisible 8px grid.
- **Pair Outfit (700 weight) with Plus Jakarta Sans (400 weight)** for clear hierarchy: emphasis vs. support.
- **Use generous padding inside cards** (`48px` minimum for feature cards) to give content breathing room.
- **Color integration badges** (WhatsApp `#25D366`, Instagram `#E4405F`, Facebook `#0084FF`) exactly as specified to maintain third-party brand integrity.
- **Apply focus states** with colored outlines (`3px solid brand-color`) and shadow escalation for keyboard navigation.

### Don't

- **Don't use blurred shadows** (`blur()` parameter)—Kirim.chat uses only hard, offset shadows.
- **Don't mix rounded and squared corners** on related components; maintain consistency within component families.
- **Don't place light text (`#64748B`) on light backgrounds**—always pair secondary text with sufficient contrast.
- **Don't use more than two font families**—stick to Outfit (headings) and Plus Jakarta Sans (body/UI).
- **Don't create buttons without visible borders**—the `2px solid #1E293B` border is mandatory for visual hierarchy.
- **Don't exceed `1200px` max-width** on desktop without justified reason; maintain focused content width.
- **Don't apply shadows to navigation elements** or full-width sections; reserve shadows for lifted, contained components.
- **Don't use the success green (`#22C55E`) for non-action elements**—reserve it for primary CTAs and confirmations.
- **Don't nest shadows deeper than `12px 12px`**—this is the maximum for most interfaces to maintain visual order.
- **Don't forget accessibility:** always maintain keyboard-navigable focus states and WCAG AA color contrast ratios.
- **Don't use custom colors for integrations**—WhatsApp, Instagram, and Facebook colors are fixed and legally protected.

## 8. Responsive Behavior

### Breakpoints

| Breakpoint Name | Width | Key Changes |
|-----------------|-------|------------|
| Mobile | `320px`–`479px` | Single column, full-width components, `8px` padding, smaller font sizes (14px body), stacked navigation |
| Mobile Large | `480px`–`767px` | Single column continued, increased padding to `12px`, font sizes increase to 16px |
| Tablet | `768px`–`1023px` | 2-column grid, `12px` gutters, `16px` padding, full typography hierarchy |
| Desktop | `1024px`–`1439px` | 3-column grid, `16px` gutters, `20px`–`32px` padding, max-width container at `1200px` |
| Desktop Large | `1440px`+ | 3-column with increased outer margins, max-width maintained at `1200px`, full feature set |

**Responsive Typography:**
- H1: `32px` (mobile) → `48px` (desktop)
- H3: `20px` (mobile) → `24px` (desktop)
- Body: `16px` (mobile) → `18px` (desktop)
- Button: `14px` (mobile) → `16px` (desktop)

**Responsive Spacing:**
- Mobile padding: `12px`–`16px`
- Tablet padding: `20px`–`24px`
- Desktop padding: `32px`–`48px`

### Touch Targets

- **Minimum touch size:** `44px × 44px` (buttons, interactive elements)
- **Comfortable touch size:** `56px × 56px` (primary buttons, important interactions)
- **Link/text targets:** Wrap in `24px`–`32px` vertical padding for mobile usability
- **Icon buttons:** `48px × 48px` minimum with `12px` internal spacing
- **Spacing between touch targets:** Minimum `8px` to prevent accidental activation

### Collapsing Strategy

**Mobile (320px–479px):**
- Stack all multi-column layouts into single column
- Hide secondary navigation; show hamburger menu
- Reduce button padding to `12px 20px`; maintain `56px` height
- Collapse feature grids into card stack
- Full-width cards with `8px` padding
- Reduce shadow offsets to `2px 2px 0px 0px`

**Tablet (480px–767px):**
- Two-column grid for feature cards
- Show abbreviated navigation; collapse deep menus
- Button padding: `12px 24px`
- Card padding: `24px`–`32px`
- Shadow offsets: `4px 4px 0px 0px`

**Desktop (768px+):**
- Full multi-column layout (3+ columns)
- Show complete navigation
- Standard button padding: `14px 28px`
- Card padding: `48px`
- Full shadow offsets: `8px 8px 0px 0px`

## 9. Agent Prompt Guide

### Quick Color Reference

- **Primary CTA:** Success Green (`#22C55E`)
- **Primary Text:** Slate 900 (`#1E293B`)
- **Secondary Text:** Slate 400 (`#64748B`)
- **Background:** White (`#FFFFFF`)
- **Borders:** Slate 900 (`#1E293B`)
- **Heading Text:** Slate 900 (`#1E293B`) with Outfit weight 700
- **Accent (Premium):** Hot Pink (`#DB2777`)
- **Success State:** Success Green (`#22C55E`)
- **Error State:** Error Red (`#E11D48`)
- **Warning State:** Warning Yellow (`#F59E0B`)
- **WhatsApp Integration:** WhatsApp Green (`#25D366`)
- **Instagram Integration:** Instagram Pink (`#E4405F`)
- **Facebook Integration:** Facebook Blue (`#0084FF`)
- **Neutral Surface:** Off-white (`#FAFBFC`, `#F1F5F9`)
- **Light Border:** Slate 200 (`#E2E8F0`)

### Iteration Guide

1. **Borders & Outlines:** Every button, card, and input **must** have a `2px solid #1E293B` border. This is non-negotiable and defines the brand.

2. **Shadows:** Use hard-offset shadows only: `box-shadow: #1E293B 4px 4px 0px 0px` (standard), `8px 8px 0px 0px` (elevated), or `2px 2px 0px 0px` (subtle). No blur radius. Scale shadows on hover/active states.

3. **Border Radius:** Buttons and pills = `9999px`. Large cards = `32px`. Standard cards = `16px`. Inputs = `8px`. Never mix rounded and square corners on related components.

4. **Typography Pairs:** Use **Outfit (700 weight)** for all headings, buttons, and emphasis. Use **Plus Jakarta Sans (400 weight)** for body, UI labels, and secondary content. Never switch these.

5. **Spacing:** All spacing values must be multiples of `4px` or `8px`. Button padding = `14px 28px`. Card padding = `48px` (large), `32px` (medium), `16px` (small). Section gaps = `40px`–`64px`.

6. **Color Hierarchy:** Green (`#22C55E`) = all primary actions. Slate 900 (`#1E293B`) = all primary text. Slate 400 (`#64748B`) = secondary/helper text. Use integration colors only for their respective platform badges.

7. **Interactive States:** Every button and link must have hover, active, and focus states. Hover = escalate shadow by `2px` or shift color darker. Active = reduce shadow or change background. Focus = `3px` outline in brand color.

8. **Touch Targets:** Ensure all interactive elements meet `44px × 44px` minimum on mobile, `56px × 56px` preferred for primary buttons. Maintain `8px` minimum spacing between targets.

9. **Responsive Collapse:** Mobile = single column, `8px`–`12px` padding, `2px 2px` shadows. Tablet = two columns, `12px`–`20px` padding, `4px 4px` shadows. Desktop = multi-column, `20px`–`48px` padding, `8px 8px` shadows.

10. **Form Elements:** All inputs = `2px solid #1E293B` border, `12px 16px` padding, `8px` border-radius. Focus state = `border-color: #047857`, `box-shadow: #047857 0px 0px 0px 3px`. Error state = `border-color: #E11D48`, `background-color: #FEE2E2`.

11. **Contrast & Accessibility:** Text on white background must use `#1E293B` (99+ WCAG contrast ratio). Helper text must use `#64748B` only on light backgrounds. All interactive elements must have visible focus states for keyboard navigation.

12. **Max Width & Centering:** Desktop layouts center at `1200px` max-width. Full-width sections use this constraint for inner content. Mobile/tablet use full viewport width minus `8px`–`20px` padding per breakpoint.