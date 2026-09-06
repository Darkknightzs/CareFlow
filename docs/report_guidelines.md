# Minor Project Report Guidelines
**Project Title**: Queue Management System for Hospitals
**Group**: BCA_15
**Leader**: Ajaypal Singh (Roll No: 2433418)
**Supervisor**: Ms. Loveleen Kaur

## Introduction & Scope (For Academic Writing)
This project is explicitly bounded as a **Core PHP Prototype** designed to solve a specific Queue Management problem, eliminating modern frontend frameworks (React/Vue) and ORMs to demonstrate fundamental algorithmic problem-solving in a lightweight environment. 

### Why Core PHP? (Defend this in your report)
- **Zero Overhead**: No dependency hell or composer requirements, making deployment instant in limited-resource hospital IT setups.
- **Direct Database Control**: Demonstrates raw SQL query writing, transaction management, and indexing (essential BCA syllabus concepts).
- **Reduced Latency**: By skipping templating engines, the server responds in low milliseconds, which is critical for live queue updates.

## Figma / UI Mapping
When writing the "System Design" or "UI Prototyping" chapter, map the final application back to your Figma designs using these descriptions:

1. **Receptionist Portal (Formerly Book Slot)**
   - *Figma Mapping*: Described as the primary entry point for administrative staff.
   - *Design Pattern*: Glassmorphism cards with dynamic gradient backgrounds.
   - *Function*: Secure entry point where Receptionists create tokens, now strictly validating duplicate same-time requests to prevent queue spoofing.

2. **Live Queue Display**
   - *Figma Mapping*: The overhead monitor display for patient waiting areas.
   - *Design Pattern*: Fixed two-column layout (`Now Serving` vs `Waiting Next`) to maximize readability from a distance.
   - *Function*: Uses lightweight AJAX polling (`fetch()`) to update the DOM strictly without meta-refresh flickers, ensuring a smooth, app-like experience.

3. **Doctor Dashboard**
   - *Figma Mapping*: The specialized console for medical professionals.
   - *Design Pattern*: Grid-based statistics counters at the top, followed by an actionable data table.
   - *Function*: Allows doctors to control queue flow (`Start`, `Complete`, `No-Show`, `Cancel`), directly feeding real-time data back to the Live Display and wait-time algorithms.

4. **Patient Token Lookup (New Feature)**
   - *Figma Mapping*: Mobile-friendly lookup screen.
   - *Design Pattern*: Minimalist card layout focused on a single call-to-action (checking status).
   - *Function*: Allows patients to query their exact status and dynamic wait time without needing a full account, maximizing accessibility.

## Academic Limitations & Future Scope
Include these in your "Conclusion" or "Future Enhancements" section:
- **Limitation**: The system relies on short-polling (AJAX) rather than WebSockets (e.g., Socket.io/Ratchet) to maintain Core PHP purity.
- **Future Scope**: Implementation of Redis caching for wait-time calculations across massive, multi-department hospitals.
- **Future Scope**: SMS integration via Twilio API to notify patients when they are 2nd in line, reducing crowded waiting rooms.

## SDG Alignment
- **Goal 3: Good Health and Well-being**
- *How we achieve it*: By calculating algorithmic wait times and providing a live token lookup, the system drastically reduces physical congestion in hospital waiting areas. This lowers the transmission risk of airborne diseases (like COVID/Flu) and reduces patient anxiety.

## Publication Note
For targeting UGC-CARE / Scopus proceedings, frame your paper around the **Wait-Time Calculation Algorithm**.
- Focus your abstract on: *"An algorithmic approach to predicting dynamic wait times in hospital queues using historical service averages and real-time delay factors."*
- Use the exported `.csv` data from the system to generate graphs (in MS Excel) comparing "Estimated Wait Time" vs "Actual Wait Time" to prove your system's efficiency in your methodology section.
