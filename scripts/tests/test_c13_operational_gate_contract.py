#!/usr/bin/env python3
"""Fail-closed evidence alignment for the legacy C13 / #734 shell inventories.

No Flutter/mobile or business logic is modified by this test. It verifies that
the existing gate scripts still point at real, current assertions instead of
historical labels, while those shells continue to enforce all their contracts.
"""
from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
C13 = ROOT / "scripts/c13-integrated-acceptance.sh"
OPERATIONAL = ROOT / "scripts/commerce-isolation-acceptance.sh"


class IntegratedAcceptanceInventoryContractTest(unittest.TestCase):
    def test_c13_static_requirements_reference_real_source(self) -> None:
        script = C13.read_text(encoding="utf-8")
        variables = dict(re.findall(r'^([a-z_]+)="([^"]+)"\s*$', script, re.M))
        checks = re.findall(r'^require_text "\$(\w+)" "([^"]+)"\s*$', script, re.M)
        self.assertGreaterEqual(len(checks), 35, "Never shrink the C13 evidence inventory unnoticed.")

        for variable, literal in checks:
            with self.subTest(variable=variable, literal=literal):
                self.assertIn(variable, variables)
                source = ROOT / variables[variable]
                self.assertTrue(source.is_file(), f"Required source missing: {source}")
                if "$symbol" in literal:
                    # The 13 canonical route tokens are expanded by the real
                    # shell loop. Verify the source contains every one, too.
                    route_codes = [
                        "entry", "b2bDashboard", "b2bPurchaseReports", "b2bTopProducts",
                        "b2bInvoices", "b2bAccountStatement", "b2bOrders",
                        "b2bOrderDetails", "b2bInvoiceDetails", "b2bProducts",
                        "b2bProductDetails", "b2bCart", "b2bProfile",
                    ]
                    self.assertEqual(len(route_codes), 13)
                    for code in route_codes:
                        self.assertIn(
                            literal.replace("$symbol", code),
                            source.read_text(encoding="utf-8"),
                        )
                else:
                    self.assertIn(literal, source.read_text(encoding="utf-8"))

        navigation = (ROOT / variables["navigation"]).read_text(encoding="utf-8")
        self.assertIn("ValueKey('unified-customer-auth-screen')", navigation)
        self.assertIn("ValueKey('customer-auth-guest')", navigation)
        self.assertIn("findsNothing", navigation)
        self.assertIn("WholesaleHomeDesignScreen", navigation)
        self.assertIn("PlatformMarketplaceScreen", navigation)

        for removed in [
            "entry route renders the C13 business login without Marketplace bypass",
            "successful C13 login lands on Screen 2 dashboard",
            "Wholesale footer has four destinations and Shopping opens Wholesale home",
        ]:
            self.assertNotIn(removed, script)

    def test_operational_contract_requires_real_foreign_deny_and_owner_history(self) -> None:
        script = OPERATIONAL.read_text(encoding="utf-8")
        checks = re.findall(r"^require_text\s+(\S+)\s+'([^']+)'\s*$", script, re.M)
        self.assertGreaterEqual(len(checks), 19, "Never shrink the #734 inventory unnoticed.")
        for filename, literal in checks:
            with self.subTest(filename=filename, literal=literal):
                source = ROOT / filename
                self.assertTrue(source.is_file(), f"Required source missing: {source}")
                self.assertIn(literal, source.read_text(encoding="utf-8"))

        order_source = (ROOT / "backend/tests/Feature/OrderOperationsIsolationTest.php").read_text(
            encoding="utf-8"
        )
        method = re.search(
            r"public function test_retail_admin_cannot_operate_foreign_order_"
            r"but_can_cancel_own_new_order\(\): void\s*\{(.*?)\n    \}",
            order_source,
            re.S,
        )
        self.assertIsNotNone(method, "The exact Retail authorization test must remain.")
        body = method.group(1)
        for assertion in [
            "->assertNotFound()",
            "['id' => $foreignOrder, 'status' => 'pending']",
            "->assertRedirect()",
            "['id' => $mineOrder, 'status' => 'cancelled']",
            "'from_status' => 'pending'",
            "'to_status' => 'cancelled'",
        ]:
            self.assertIn(assertion, body)
        self.assertIn(
            "test_dashboard_rejects_inactive_lookup_status_and_illegal_lifecycle_jump",
            order_source,
        )
        self.assertNotIn(
            "test_retail_admin_cannot_operate_foreign_order_but_can_transition_own_order",
            script,
        )


if __name__ == "__main__":
    unittest.main()
