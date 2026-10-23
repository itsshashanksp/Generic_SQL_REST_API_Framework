SELECT
    LEFT(CONVERT(VARCHAR(8), BIL.Bill_Date), 4) + '-' + SUBSTRING(CONVERT(VARCHAR(8), BIL.Bill_Date), 5, 2) AS Month,
    CAT.Cat_Desc AS Category,
    ROUND(SUM(CASE WHEN BIL.Status = 'S' THEN (BIL.Item_Value - BIL.Disc_Amt - BIL.Item_RndOff) ELSE -1 * (BIL.Item_Value - BIL.Disc_Amt - BIL.Item_RndOff) END) / 100000, 2) AS Sales
FROM BillDetTable BIL, CategoryTable CAT
WHERE
    BIL.Cat_Code = CAT.Cat_Code
    AND BIL.Bill_Date BETWEEN
        (CASE WHEN MONTH(GETDATE()) <= 3 THEN YEAR(GETDATE()) - 1 ELSE YEAR(GETDATE()) END) * 10000 + 401
        AND (CASE WHEN MONTH(GETDATE()) <= 3 THEN YEAR(GETDATE()) ELSE YEAR(GETDATE()) + 1 END) * 10000 + 331
GROUP BY
    LEFT(CONVERT(VARCHAR(8), BIL.Bill_Date), 4) + '-' + SUBSTRING(CONVERT(VARCHAR(8), BIL.Bill_Date), 5, 2),
    CAT.Cat_Desc
ORDER BY Month, Sales DESC
