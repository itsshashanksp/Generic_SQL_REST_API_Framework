SELECT TOP 10
    CAT.Cat_Desc AS Category,
    ROUND(SUM(BIL.Item_Rate) / 100000, 0) AS Sales
FROM BillDetTable BIL, CategoryTable CAT
WHERE
    BIL.Cat_Code = CAT.Cat_Code
    AND BIL.Bill_NETT > 0
GROUP BY CAT.Cat_Desc
ORDER BY Sales DESC