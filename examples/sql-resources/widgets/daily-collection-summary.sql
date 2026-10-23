SELECT
    RIGHT(CONVERT(VARCHAR(8), Bill_Date), 2) + '/' + SUBSTRING(CONVERT(VARCHAR(8), Bill_Date), 5, 2) + '/' + LEFT(CONVERT(VARCHAR(8), Bill_Date), 4) AS Date,
    COUNT(DISTINCT Bill_No) AS Receipt_Count,
    ROUND(SUM(Amount - Com_Amt), 2) AS Total_Collection,
    ROUND(SUM(Com_Amt), 2) AS Total_Commission,
    Bill_Date
FROM BillRectTable
GROUP BY Bill_Date
ORDER BY Bill_Date DESC
