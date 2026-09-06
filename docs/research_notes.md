# Research Methodology: Queue Management System

## Project Context
Project ID: BCA_15
Topic: Queue Management System for Hospitals
Expected Outcome: SCI/Scopus Journal Publication

## Objective
To digitally optimize outpatient department (OPD) wait times and prove a statistically significant reduction in patient wait time and hospital resource mismanagement.

## Data Collection via the System
The developed system captures crucial timestamps automatically:
1. **`arrival_time`**: When the token is generated at the kiosk/portal.
2. **`service_start_time`**: When the doctor clicks "Start Service".
3. **`service_end_time`**: When the doctor clicks "Complete Service".

## Utilizing Data for Journal Publication
The `export.php` script provides a daily CSV containing these exact metrics. To formulate your research paper, follow these steps:

### 1. Baseline Data (Manual Process)
Before fully deploying the system, observe the hospital for a week and manually record wait times using a stopwatch. This forms your "Control Group".

### 2. Post-Deployment Data (Digital Process)
Deploy the system. Use the exported CSV data over a period of 4 weeks. This is your "Experimental Group".

### 3. Key Metrics to Analyze in Microsoft Excel
Import the CSV into Excel and generate the following analyses:
- **Average Total Wait Time**: Compare manual vs. digital.
- **Service Duration Variance**: Analyze if doctors are spending consistent time per patient.
- **Queue Length Optimization**: Graph the peak hours (using `arrival_time`) to suggest better staff allocation.

### 4. Hypothesis Testing
Use a T-Test in Excel (`T.TEST`) comparing the manual wait times vs digital wait times. A p-value of `< 0.05` proves that your system caused a statistically significant reduction in waiting times.

### 5. SDG Goal Alignment
In your paper's conclusion, explicitly tie the reduction of wait time to **SDG 3 (Good Health and Well-being)** by arguing that reduced waiting time decreases patient anxiety and exposure to secondary infections in waiting rooms.
