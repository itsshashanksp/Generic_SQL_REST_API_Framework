SELECT
    COUNT(Item_Code) AS TotalItems,
    MIN(Sale_Rate) AS MinimumSP,
    MAX(Sale_Rate) AS MaximumSP,
    SUM(Sale_Rate*Cl_Stock) AS StockValue
FROM ItemMasterTable
