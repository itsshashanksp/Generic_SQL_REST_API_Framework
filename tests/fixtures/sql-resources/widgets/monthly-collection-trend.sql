SELECT
    LEFT(CONVERT(VARCHAR(8), Bill_Date), 4) + '-' + SUBSTRING(CONVERT(VARCHAR(8), Bill_Date), 5, 2) AS Month,
    COUNT(DISTINCT Bill_No) AS Receipt_Count,
    ROUND(SUM(Amount - Com_Amt), 2) AS Total_Collection
FROM BillRectTable
GROUP BY LEFT(CONVERT(VARCHAR(8), Bill_Date), 4) + '-' + SUBSTRING(CONVERT(VARCHAR(8), Bill_Date), 5, 2)
ORDER BY Month DESC
