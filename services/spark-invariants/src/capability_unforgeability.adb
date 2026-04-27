--  Capability token unforgeability — SPARK 2014 implementation.
--
--  See package spec for the contracts proved by GNATprove. The body is
--  a placeholder for Sprint 1.2 (capability sprint). At Phase 0 the body
--  raises Program_Error at runtime if invoked — the FFI symbol exists
--  but the proven implementation lands at Sprint 1.2 along with the
--  kernel capability table.

with System;
with System.Storage_Elements; use System.Storage_Elements;

package body Capability_Unforgeability
  with SPARK_Mode => On
is

   --  Ghost membership predicate. Refines to "Tok is in Set's bytes" at
   --  Sprint 1.2; at Phase 0 unspecified body for the abstract type.
   function Is_Member
     (Tok : Capability_Token;
      Set : Capability_Set) return Boolean
   is (raise Program_Error)  --  Phase 0 placeholder; refines at Sprint 1.2.
   with SPARK_Mode => Off;   --  Body is non-SPARK at Phase 0.

   procedure Mint_Capability
     (Tok    : out Capability_Token;
      Set    : in out Capability_Set;
      Caller :     Capability_Token)
   is
      pragma Unreferenced (Caller);
   begin
      Tok := (others => 0);  --  Phase 0 placeholder.
      raise Program_Error;
   end Mint_Capability;

   function Verify_Capability
     (Tok : Capability_Token;
      Set : Capability_Set) return Boolean
   is
   begin
      return Is_Member (Tok, Set);
   end Verify_Capability;

   --  ──────────────────────────────────────────────────────────────────
   --  C ABI implementations
   --  ──────────────────────────────────────────────────────────────────

   function C_Verify_Capability
     (Token_Bytes : System.Address;
      Token_Len   : Natural;
      Set_Handle  : System.Address) return Integer
   is
      pragma Unreferenced (Token_Bytes, Set_Handle);
   begin
      --  Phase 0: signal "verification declined; implementation pending".
      if Token_Len /= 32 then
         return 0;
      end if;
      return 0;
   end C_Verify_Capability;

end Capability_Unforgeability;
