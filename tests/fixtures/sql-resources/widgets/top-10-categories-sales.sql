SELECT TOP 10
    CAT.Cat_Desc AS Category,
    ROUND(SUM(CASE WHEN BIL.Status = 'S' THEN (BIL.Item_Value - BIL.Disc_Amt - BIL.Item_RndOff) ELSE -1 * (BIL.Item_Value - BIL.Disc_Amt - BIL.Item_RndOff) END) / 100000, 2) AS Sales
FROM BillDetTable BIL, CategoryTable CAT
WHERE
    BIL.Cat_Code = CAT.Cat_Code
    AND BIL.Bill_Date BETWEEN
        (CASE WHEN MONTH(GETDATE()) <= 3 THEN YEAR(GETDATE()) - 1 ELSE YEAR(GETDATE()) END) * 10000 + 401
        AND (CASE WHEN MONTH(GETDATE()) <= 3 THEN YEAR(GETDATE()) ELSE YEAR(GETDATE()) + 1 END) * 10000 + 331
GROUP BY CAT.Cat_Desc
ORDER BY Sales DESC
