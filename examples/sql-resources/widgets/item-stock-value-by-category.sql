SELECT
    (SELECT CAT.Cat_Desc FROM CategoryTable CAT WHERE CAT.Cat_Code = ITM.Cat_Code) AS Category,
    ROUND(SUM(ITM.Sale_Rate * ITM.Cl_Stock), 2) AS StockValue
FROM ItemMasterTable ITM
WHERE EXISTS (SELECT 1 FROM CategoryTable CAT WHERE CAT.Cat_Code = ITM.Cat_Code)
GROUP BY ITM.Cat_Code
ORDER BY StockValue DESC
