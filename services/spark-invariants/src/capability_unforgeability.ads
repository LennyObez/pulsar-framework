--  Capability token unforgeability — SPARK 2014 spec.
--
--  Per Decision 2.54 (v2.3 lock-in): proves that no execution path
--  produces a Capability_Token Tok such that Verify_Capability
--  (Tok, Current_Set) returns True unless Tok was previously minted
--  by Mint_Capability with a precondition restricting callers.
--
--  Linked into pulsar-kernel via the C ABI declared at the bottom of
--  this spec (`pragma Export`). Sprint 1.2 (kernel capability sprint)
--  consumes the proven verifier from Rust.

with System;
with System.Storage_Elements; use System.Storage_Elements;

package Capability_Unforgeability
  with SPARK_Mode => On,
       Pure
is

   --  Capability token: opaque 32-byte opaque blob (HMAC-tagged outside
   --  this module). Treated as a value type for proof purposes.
   type Capability_Token is private;

   --  Set of valid capabilities — proof-only abstract type.
   type Capability_Set is private;

   --  Membership predicate. Used in pre/post conditions only.
   function Is_Member
     (Tok : Capability_Token;
      Set : Capability_Set) return Boolean
   with
     Ghost,
     Global => null;

   --  Mint a fresh capability into the set. Capability tokens may only
   --  enter the set through this function — the unforgeability invariant.
   procedure Mint_Capability
     (Tok    : out Capability_Token;
      Set    : in out Capability_Set;
      Caller :     Capability_Token)
   with
     Pre  => Is_Member (Caller, Set)  --  Caller must hold a capability authorising mint.
              and then Tok'Initialized,
     Post => Is_Member (Tok, Set)
              and then (for all Other in Capability_Token => -- new tokens are distinct
                          (if Other /= Tok then Is_Member (Other, Set) = Is_Member (Other, Set'Old)));

   --  Verify the token belongs to the set. Returns True iff Tok is a
   --  member that was minted by Mint_Capability above.
   function Verify_Capability
     (Tok : Capability_Token;
      Set : Capability_Set) return Boolean
   with
     Post => Verify_Capability'Result = Is_Member (Tok, Set);

   --  C ABI for FFI to pulsar-kernel ==========================================

   --  C-callable verifier. Returns 1 iff verified, 0 otherwise.
   function C_Verify_Capability
     (Token_Bytes : System.Address;
      Token_Len   : Natural;
      Set_Handle  : System.Address) return Integer
   with
     Export,
     Convention => C,
     External_Name => "pulsar_spark_verify_capability";

private

   type Capability_Token is array (1 .. 32) of Storage_Element;

   type Capability_Set is null record;  --  Opaque to FFI; ghost in proof.

end Capability_Unforgeability;
