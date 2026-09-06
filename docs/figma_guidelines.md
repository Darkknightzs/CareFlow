# Figma Design Guidelines: Hospital Queue Management System

## Objective
The UI must be highly accessible, especially for elderly patients, maintaining high contrast and large touch targets.

## General Design System
- **Color Palette**: 
  - Primary: Deep Blue (#1d4ed8) - conveys trust and medical professionalism.
  - Success: Green (#15803d) - used for completed actions.
  - Alert/Warning: Yellow (#fef08a) - used for waiting status.
  - Background: Off-white/Light Gray (#f3f4f6) - reduces eye strain compared to pure white.
- **Typography**: Sans-serif (e.g., Inter or Roboto). Large base font size (18px) for readability.
- **Components**:
  - Buttons: Minimum height 48px for easy tapping on mobile.
  - Inputs: Thick borders (2px) and large padding.

## Screen 1: Patient Mobile View (Portal)
- **Target Device**: Mobile (375x812)
- **Layout**:
  - Header: Fixed at the top, "Hospital Queue System".
  - Main Form: Single-column layout.
  - Labels: Bold and placed *above* the inputs.
  - Inputs: Full width.
  - Call to Action: Large, sticky bottom button "Generate Token".
- **Post-Submission**:
  - Display the generated Token (e.g., CAR-001) in extremely large, bold text (e.g., 40px).
  - Display the "Estimated Wait Time" directly below it in a highlighted box.

## Screen 2: Doctor Dashboard
- **Target Device**: Desktop/Tablet (1024x768+)
- **Layout**:
  - Data Table: Clean rows with alternating background colors (zebra striping) for readability.
  - Status Pills: Badges with background colors (Yellow = Waiting, Blue = In-Progress, Green = Completed).
  - Actions: Clearly separated buttons to "Start Service" and "Complete".

## Screen 3: Live Display Screen
- **Target Device**: Large TV/Monitor (1920x1080)
- **Layout**: 
  - Dark Mode: Black/Dark Gray background to reduce glare in waiting rooms.
  - 2-Column Split:
    - Left Column (Now Serving): Highlighted with a green glow, massive text for the current token and doctor.
    - Right Column (Waiting Next): A grid of the next 5-10 tokens waiting, slightly smaller text.
  - Footer: "Auto-refreshes every 10 seconds".
