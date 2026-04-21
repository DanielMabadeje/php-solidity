// SPDX-License-Identifier: MIT
pragma solidity ^0.8.20;

contract MyToken {
    string public name;
    string public symbol;
    uint8 public decimals;
    uint256 public totalSupply;
    mapping balances;
    mapping allowances;
    address immutable private owner;

    event Transfer(address indexed from, address indexed to, uint256 value);
    event Approval(address indexed owner, address indexed spender, uint256 value);
    event OwnershipTransferred(address indexed previousOwner, address indexed newOwner);

    modifier onlyOwner() {
        require(msg.sender == owner, "Ownable: caller is not the owner");
        _;
    }

    constructor(string name, string symbol, uint256 initialSupply) {
        name = name;
        symbol = symbol;
        decimals = new uint8(18);
        totalSupply = initialSupply;
        owner = msg.sender;
        balances[msg.sender] = initialSupply;
        emit Transfer(address(0), msg.sender, initialSupply);
    }

    function balanceOf(address account) external view returns (uint256) {
        return balances[account];
    }

    function allowance(address owner, address spender) external view returns (uint256) {
        return allowances[owner][spender];
    }

    function transfer(address to, uint256 amount) external returns (bool) {
        require(balances[msg.sender] >= amount, "ERC20: insufficient balance");
        require(to != address(0), "ERC20: transfer to zero address");
        balances[msg.sender] -= amount;
        balances[to] += amount;
        emit Transfer(msg.sender, to, amount);
        return true;
    }

    function approve(address spender, uint256 amount) external returns (bool) {
        require(spender != address(0), "ERC20: approve to zero address");
        allowances[msg.sender][spender] = amount;
        emit Approval(msg.sender, spender, amount);
        return true;
    }

    function transferFrom(address from, address to, uint256 amount) external returns (bool) {
        currentAllowance = allowances[from][msg.sender];
        require(currentAllowance >= amount, "ERC20: insufficient allowance");
        require(balances[from] >= amount, "ERC20: insufficient balance");
        require(to != address(0), "ERC20: transfer to zero address");
        allowances[from][msg.sender] -= amount;
        balances[from] -= amount;
        balances[to] += amount;
        emit Transfer(from, to, amount);
        return true;
    }

    function mint(address to, uint256 amount) external onlyOwner {
        require(to != address(0), "ERC20: mint to zero address");
        totalSupply += amount;
        balances[to] += amount;
        emit Transfer(address(0), to, amount);
    }

    function burn(address from, uint256 amount) external onlyOwner {
        require(balances[from] >= amount, "ERC20: burn exceeds balance");
        balances[from] -= amount;
        totalSupply -= amount;
        emit Transfer(from, address(0), amount);
    }

    function transferOwnership(address newOwner) external onlyOwner {
        require(newOwner != address(0), "Ownable: new owner is zero address");
        emit OwnershipTransferred(owner, newOwner);
        owner = newOwner;
    }
}
