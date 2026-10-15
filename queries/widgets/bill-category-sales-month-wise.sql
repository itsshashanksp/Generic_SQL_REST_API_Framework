SELECT
    SUBSTRING(CONVERT(VARCHAR, BIL.Bill_Date), 5, 2) AS Month,
    CAT.Cat_Desc AS Category,
    ROUND(SUM(BIL.Item_Rate) / 1000, 0) AS Sales
FROM BillDetTable BIL, CategoryTable CAT
WHERE
    BIL.Cat_Code = CAT.Cat_Code
    -- AND BIL.Bill_Amt > 100
GROUP BY
    SUBSTRING(CONVERT(VARCHAR, BIL.Bill_Date), 5, 2),
    CAT.Cat_Desc
HAVING ROUND(SUM(BIL.Item_Rate) / 1000, 0) > 15
ORDER BY Month, Category