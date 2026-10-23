SELECT
    Cust_Name,
    COUNT(Cust_Name) AS TotalCustomers,
    MIN(Bill_Amt) AS MinimumBill,
    MAX(Bill_Amt) AS MaximumBill
FROM CustomerTable
GROUP BY Cust_Name
