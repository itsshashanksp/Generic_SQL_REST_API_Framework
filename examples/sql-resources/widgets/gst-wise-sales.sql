SELECT
    BIL.Vat AS GST_Per,
    ROUND(SUM(CASE WHEN BIL.Status = 'S' THEN (BIL.Item_Value - BIL.Disc_Amt - BIL.Vat_Amount - BIL.Item_RndOff) ELSE -1 * (BIL.Item_Value - BIL.Disc_Amt - BIL.Vat_Amount - BIL.Item_RndOff) END), 2) AS SalesOnAmt,
    ROUND(SUM(CASE WHEN BIL.Status = 'S' THEN BIL.Vat_Amount ELSE -1 * BIL.Vat_Amount END), 2) AS GST_Amount,
    ROUND(SUM(CASE WHEN BIL.Status = 'S' THEN (BIL.Item_Value - BIL.Disc_Amt - BIL.Item_RndOff) ELSE -1 * (BIL.Item_Value - BIL.Disc_Amt - BIL.Item_RndOff) END), 2) AS TotalSalesAmount
FROM BillDetTable BIL
GROUP BY BIL.Vat
ORDER BY GST_Per
