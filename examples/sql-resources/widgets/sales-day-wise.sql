SELECT
    RIGHT(CONVERT(VARCHAR(8), Bill_Date), 2) + '/' + SUBSTRING(CONVERT(VARCHAR(8), Bill_Date), 5, 2) + '/' + LEFT(CONVERT(VARCHAR(8), Bill_Date), 4) AS Date,
    ROUND(SUM(Bill_Nett), 2) AS Sales,
    Bill_Date
FROM BillMastTable
GROUP BY Bill_Date
ORDER BY Bill_Date DESC
