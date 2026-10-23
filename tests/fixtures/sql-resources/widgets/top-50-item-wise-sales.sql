SELECT TOP 50
    BIL.Item_Code,
    BIL.Item_Desc,
    ROUND(SUM(CASE WHEN BIL.Status = 'S' THEN (BIL.Item_Value - BIL.Disc_Amt - BIL.Item_RndOff) ELSE -1 * (BIL.Item_Value - BIL.Disc_Amt - BIL.Item_RndOff) END), 2) AS Sales
FROM BillDetTable BIL
GROUP BY BIL.Item_Code, BIL.Item_Desc
ORDER BY Sales DESC
