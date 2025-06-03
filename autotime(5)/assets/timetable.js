/**
 * Timetable Generator JavaScript Functions
 * This file handles client-side functionality for the timetable generator
 */

// ExportToExcel function - Uses table export functionality
function exportToExcel() {
    // Get the timetable element
    const table = document.querySelector('.timetable');
    
    if (!table) {
        alert('No timetable found to export!');
        return;
    }
    
    // Create a workbook and add the worksheet
    let csv = '';
    
    // Add headers
    const headerRow = table.querySelectorAll('thead tr th');
    let headers = [];
    headerRow.forEach(th => {
        let headerText = th.textContent.trim().replace(/\s+/g, ' ');
        headers.push(`"${headerText}"`);
    });
    csv += headers.join(',') + '\r\n';
    
    // Add rows
    const rows = table.querySelectorAll('tbody tr');
    rows.forEach(row => {
        const cells = row.querySelectorAll('td');
        let rowData = [];
        
        cells.forEach(cell => {
            // Get text content from the cell, stripping out HTML tags
            let cellContent = cell.textContent.trim().replace(/\s+/g, ' ');
            rowData.push(`"${cellContent}"`);
        });
        
        csv += rowData.join(',') + '\r\n';
    });
    
    // Create a CSV download
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    
    link.setAttribute('href', url);
    link.setAttribute('download', 'timetable.csv');
    link.style.visibility = 'hidden';
    
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

// Function to highlight scheduling conflicts in the timetable
function highlightConflicts() {
    const staffAssignments = {};
    const conflictCells = [];
    
    // Get all course cells with staff assignments
    const courseCells = document.querySelectorAll('.course-cell:not(.course-break)');
    
    courseCells.forEach(cell => {
        const row = cell.closest('tr');
        const dayCell = row.querySelector('.timetable-day');
        const day = dayCell.textContent.trim();
        
        const headerIndex = Array.from(cell.parentNode.children).indexOf(cell);
        const headerCell = document.querySelector(`thead tr th:nth-child(${headerIndex + 1})`);
        const period = headerCell.textContent.split('\n')[0].trim();
        
        const staffName = cell.querySelector('.course-staff').textContent.trim();
        
        if (staffName) {
            const key = `${day}-${period}-${staffName}`;
            
            if (staffAssignments[key]) {
                // This is a conflict - staff already assigned to this day/period
                conflictCells.push(cell);
                conflictCells.push(staffAssignments[key]);
            } else {
                staffAssignments[key] = cell;
            }
        }
    });
    
    // Highlight all cells that have conflicts
    conflictCells.forEach(cell => {
        cell.classList.add('conflict');
        cell.style.backgroundColor = '#ffdddd';
        cell.style.border = '2px solid #ff0000';
    });
    
    return conflictCells.length > 0;
}

// Function to sort timetable by time
function sortTableByTime() {
    const table = document.querySelector('.timetable');
    if (!table) return;
    
    const thead = table.querySelector('thead');
    const headerRow = thead.querySelector('tr');
    const headers = Array.from(headerRow.querySelectorAll('th'));
    
    // Skip the first header (Day column)
    const timeHeaders = headers.slice(1);
    
    // Extract time information and create sortable data
    const timeData = timeHeaders.map((header, index) => {
        const timeSpan = header.querySelector('.time');
        if (!timeSpan) return { index: index + 1, time: '00:00' };
        
        const timeText = timeSpan.textContent.trim();
        const startTime = timeText.split('-')[0].trim();
        
        return {
            index: index + 1,
            time: startTime
        };
    });
    
    // Sort headers by time
    timeData.sort((a, b) => {
        return a.time.localeCompare(b.time);
    });
    
    // Create a mapping of current position to sorted position
    const orderMap = {};
    timeData.forEach((item, sortedIndex) => {
        orderMap[item.index] = sortedIndex + 1;
    });
    
    // Reorder the columns in the body rows
    const tbody = table.querySelector('tbody');
    const rows = Array.from(tbody.querySelectorAll('tr'));
    
    rows.forEach(row => {
        const cells = Array.from(row.querySelectorAll('td'));
        const dayCell = cells[0];
        const dataCells = cells.slice(1);
        
        // Clear the row
        while (row.firstChild) {
            row.removeChild(row.firstChild);
        }
        
        // Add the day cell back
        row.appendChild(dayCell);
        
        // Add cells in the sorted order
        timeData.forEach(item => {
            const originalIndex = item.index - 1;
            if (originalIndex < dataCells.length) {
                row.appendChild(dataCells[originalIndex].cloneNode(true));
            }
        });
    });
}

// Document Ready Function
document.addEventListener('DOMContentLoaded', function() {
    // Automatically highlight conflicts when the page loads
    const hasConflicts = highlightConflicts();
    
    if (hasConflicts) {
        console.warn('Scheduling conflicts detected and highlighted in the timetable.');
    }
});