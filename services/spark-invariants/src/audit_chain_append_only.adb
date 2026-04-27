--  Audit chain append-only enforcement — SPARK 2014 implementation.
--
--  See package spec for the contracts proved by GNATprove. The body is
--  a placeholder for Sprint 1.2 (audit + capability sprint). At Phase 0
--  the FFI symbol exists but always returns "verification declined".

pragma SPARK_Mode (On);

package body Audit_Chain_Append_Only is

   function Verify_Append
     (Prev_Root : Merkle_Root;
      Entry_Bytes : Audit_Entry;
      New_Root  : Merkle_Root) return Boolean
   is
      pragma Unreferenced (Prev_Root, Entry_Bytes, New_Root);
   begin
      --  Phase 0 placeholder: implementation lands at Sprint 1.2.
      return False;
   end Verify_Append;

   --  ──────────────────────────────────────────────────────────────────
   --  C ABI implementations
   --  ──────────────────────────────────────────────────────────────────

   function C_Verify_Append
     (Prev_Root_Bytes : System.Address;
      Entry_Bytes_Ptr : System.Address;
      Entry_Bytes_Len : Natural;
      New_Root_Bytes  : System.Address) return Integer
   is
      pragma Unreferenced (Prev_Root_Bytes, Entry_Bytes_Ptr, Entry_Bytes_Len, New_Root_Bytes);
   begin
      return 0;
   end C_Verify_Append;

end Audit_Chain_Append_Only;
